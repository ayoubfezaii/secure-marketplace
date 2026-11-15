<?php

/**
 * FILE: src/Service/AccountManager.php
 * WHAT: service that changes the role of an account (buyer <-> seller) and everything that goes with it.
 * ROLE IN THE PROJECT: a service holds business logic shared by several controllers. This one is the single
 *   door for role changes, which protects against privilege escalation.
 * USED BY: Admin\ApplicationController (approve / reject an application) and Admin\UserController (switch role).
 *   Only admins reach it.
 */

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\SellerApplication;
use App\Entity\Shop;
use App\Entity\User;
use App\Repository\ShopRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Role changes (rule 4, least privilege; rule 14, audit).
 *
 * Roles are EXCLUSIVE: an account is exactly one of BUYER, SELLER or ADMIN. This service is the only place that
 * switches a buyer to seller (application approved, or admin switch) or back, and it keeps the data consistent:
 *  - becoming a seller: a Shop is created (unique slug) and the buyer's cart is emptied (sellers can't buy);
 *  - becoming a buyer again: all the seller's products are hidden from the catalogue (their shop page returns 404).
 * ROLE_ADMIN can never be granted here: admins are created only by the app:create-admin console command.
 * Symfony notices the role change on the user's next request and logs them out (no stale privileges).
 */
final class AccountManager
{
    /**
     * @param EntityManagerInterface $em    Doctrine, to save and to run transactions
     * @param ShopRepository         $shops to find a free shop address (slug)
     * @param AuditLogger            $audit writes into the audit log
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShopRepository $shops,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Approves a seller application: the applicant becomes a seller and gets a shop. Admin only.
     *
     * @param SellerApplication $application the pending application
     * @param User              $admin       the logged-in admin who decides
     */
    public function approveApplication(SellerApplication $application, User $admin): void
    {
        // One database transaction: either everything is saved (decision + role + shop) or nothing.
        $this->em->wrapInTransaction(function () use ($application, $admin): void {
            $application->approve($admin);
            $this->makeSeller($application->getApplicant(), $application->getShopName(), $application->getDescription());
            $this->em->flush();
        });

        // SECURITY: logging - the approval and the role change are both written in the audit log.
        $this->audit->log(AuditLog::APPLICATION_APPROVED, ['application' => (string) $application->getUuid(), 'applicant' => $application->getApplicant()->getEmail()]);
        $this->audit->log(AuditLog::ROLE_CHANGED, ['target' => $application->getApplicant()->getEmail(), 'from' => [User::ROLE_BUYER], 'to' => [User::ROLE_SELLER], 'via' => 'application']);
    }

    /**
     * Rejects a seller application with a reason. Admin only. The role does not change.
     *
     * @param string $reason explanation shown to the applicant
     */
    public function rejectApplication(SellerApplication $application, User $admin, string $reason): void
    {
        $application->reject($admin, $reason);
        $this->em->flush();

        $this->audit->log(AuditLog::APPLICATION_REJECTED, ['application' => (string) $application->getUuid(), 'applicant' => $application->getApplicant()->getEmail()]);
    }

    /**
     * Admin switch buyer <-> seller.
     *
     *
     * @param User $user the account to change (never an admin)
     * @param string $target 'buyer' or 'seller' (anything else is refused)
     */
    public function switchRole(User $user, string $target): void
    {
        // SECURITY (rule 9): fixed whitelist, ROLE_ADMIN is unreachable.
        $role = match ($target) {
            'buyer' => User::ROLE_BUYER,
            'seller' => User::ROLE_SELLER,
            default => throw new \InvalidArgumentException('Invalid role.'),
        };
        if ($user->isAdmin()) {
            throw new \DomainException('Administrator accounts are managed from the console.');
        }
        // Remember the old role for the audit log.
        $from = $user->getRoles();

        // One transaction for the role change and its side effects.
        $this->em->wrapInTransaction(function () use ($user, $role): void {
            if (User::ROLE_SELLER === $role) {
                // Buyer -> seller: give the role and create a shop named after the display name.
                $this->makeSeller($user, $user->getDisplayName() ?? 'Shop '.substr($user->getUuid()->toBase58(), 0, 6), null);
            } else {
                $user->setRoles([User::ROLE_BUYER]);
                // A former seller's listings are hidden (not deleted: past orders keep their references).
                $this->em->createQueryBuilder()->update(Product::class, 'p')->set('p.isActive', ':off')
                    ->where('p.seller = :u')->setParameter('off', false)->setParameter('u', $user)
                    ->getQuery()->execute();
            }
            $this->em->flush();
        });

        $this->audit->log(AuditLog::ROLE_CHANGED, ['target' => $user->getEmail(), 'from' => $from, 'to' => $user->getRoles(), 'via' => 'admin']);
    }

    /**
     * Common code "this account becomes a seller": sets the role, creates the shop if needed, empties the cart.
     *
     * @param User        $user        the future seller
     * @param string      $shopName    name of the new shop
     * @param string|null $description description of the new shop
     */
    private function makeSeller(User $user, string $shopName, ?string $description): void
    {
        $user->setRoles([User::ROLE_SELLER]);
        if (null === $user->getShop()) {
            // Create the shop with a unique address (slug). The name is cut to the column size.
            $shop = new Shop($user, mb_substr($shopName, 0, 80), $this->shops->uniqueSlug($shopName));
            $shop->setDescription($description);
            $this->em->persist($shop);
        }
        // Sellers can't buy: the old cart is emptied.
        $this->em->createQueryBuilder()->delete(CartItem::class, 'c')->where('c.user = :u')->setParameter('u', $user)->getQuery()->execute();
    }
}
