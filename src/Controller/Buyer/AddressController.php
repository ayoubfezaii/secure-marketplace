<?php

/**
 * FILE: src/Controller/Buyer/AddressController.php
 * WHAT: controller of the buyer's address book: list, add, edit, set default, delete.
 * ROLE IN THE PROJECT: a complete CRUD example protected against IDOR, CSRF and mass assignment.
 * USED BY: buyers only (ROLE_BUYER). URLs start with /buyer/addresses.
 */

namespace App\Controller\Buyer;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Form\AddressFormType;
use App\Repository\AddressRepository;
use App\Security\Voter\AddressVoter;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Saved shipping addresses (max 5).
 *
 * SECURITY: UUIDs in URLs + AddressVoter on every object (rule 4, IDOR); owner set from the session and explicit
 * form fields (rule 9); every change is a POST with a CSRF token (rule 3) and is audit-logged (rule 14).
 */
#[IsGranted('ROLE_BUYER')]
#[Route('/buyer/addresses')]
class AddressController extends AbstractController
{
    /**
     * @param AddressRepository      $addresses queries on addresses
     * @param EntityManagerInterface $em        Doctrine, to save / delete
     * @param AuditLogger            $audit     writes into the audit log
     */
    public function __construct(
        private readonly AddressRepository $addresses,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** List of the buyer's addresses. Buyer only. */
    #[Route('', name: 'buyer_addresses', methods: ['GET'])]
    public function index(): Response
    {
        // SECURITY: IDOR - only the addresses of the logged-in buyer are read.
        $list = $this->addresses->findForUser($this->buyer());

        return $this->render('buyer/addresses/index.html.twig', ['addresses' => $list, 'max' => Address::MAX_PER_USER]);
    }

    /**
     * Form to add an address (GET shows it, POST saves it). Buyer only.
     */
    #[Route('/new', name: 'buyer_address_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $buyer = $this->buyer();
        // SECURITY (rule 15): hard cap, checked on the server.
        if ($this->addresses->countForUser($buyer) >= Address::MAX_PER_USER) {
            $this->addFlash('error', sprintf('You can save up to %d addresses.', Address::MAX_PER_USER));

            return $this->redirectToRoute('buyer_addresses');
        }

        // SECURITY (rule 9): the owner comes from the session, never from the form.
        $address = new Address($buyer);
        $address->setFullName($buyer->getDisplayName());
        // Symfony form: fills the address, checks the CSRF token and validates the entity constraints.
        $form = $this->createForm(AddressFormType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The very first address of a buyer becomes the default one.
            $first = 0 === $this->addresses->countForUser($buyer);
            $this->em->persist($address);
            $this->em->flush();
            if ($address->isDefault() || $first) {
                $address->setIsDefault(true);
                $this->addresses->makeDefault($address);
                $this->em->flush();
            }
            $this->audit->log(AuditLog::ADDRESS_CREATED, ['address' => (string) $address->getUuid()]);
            $this->addFlash('success', 'Address saved.');

            return $this->redirectToRoute('buyer_addresses');
        }

        return $this->render('buyer/addresses/form.html.twig', ['form' => $form, 'address' => null], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Form to edit an address. Buyer only, and only the owner of the address.
     *
     * @param Address $address loaded by the UUID of the URL (404 if unknown)
     */
    #[Route('/{uuid}/edit', name: 'buyer_address_edit', methods: ['GET', 'POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function edit(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Address $address): Response
    {
        // SECURITY (rule 4, IDOR): another buyer's address UUID -> 403.
        $this->denyAccessUnlessGranted(AddressVoter::EDIT, $address);

        $form = $this->createForm(AddressFormType::class, $address);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // If "default" was ticked, remove the flag from the other addresses.
            if ($address->isDefault()) {
                $this->addresses->makeDefault($address);
            }
            $this->em->flush();
            $this->audit->log(AuditLog::ADDRESS_UPDATED, ['address' => (string) $address->getUuid()]);
            $this->addFlash('success', 'Address updated.');

            return $this->redirectToRoute('buyer_addresses');
        }
        // Invalid form: reload the address from the database so the wrong values are not kept in memory.
        if ($form->isSubmitted()) {
            $this->em->refresh($address);
        }

        return $this->render('buyer/addresses/form.html.twig', ['form' => $form, 'address' => $address], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Makes an address the default one. POST only. Buyer only, owner only.
     */
    #[Route('/{uuid}/default', name: 'buyer_address_default', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function makeDefault(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Address $address): Response
    {
        // SECURITY: CSRF then IDOR - first the token, then the voter checks the address belongs to the buyer.
        $this->assertCsrf($request, 'address-default-'.$address->getUuid());
        $this->denyAccessUnlessGranted(AddressVoter::EDIT, $address);

        $this->addresses->makeDefault($address);
        $this->em->flush();
        $this->addFlash('success', sprintf('"%s" is now your default address.', $address->getLabel()));

        return $this->redirectToRoute('buyer_addresses');
    }

    /**
     * Deletes an address. POST only. Buyer only, owner only.
     */
    #[Route('/{uuid}/delete', name: 'buyer_address_delete', methods: ['POST'], requirements: ['uuid' => Requirement::UUID_V4])]
    public function delete(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] Address $address): Response
    {
        // SECURITY: CSRF then IDOR - first the token, then the voter checks the address belongs to the buyer.
        $this->assertCsrf($request, 'address-delete-'.$address->getUuid());
        $this->denyAccessUnlessGranted(AddressVoter::DELETE, $address);

        // Remember two values before deleting.
        $wasDefault = $address->isDefault();
        $uuid = (string) $address->getUuid();
        $this->em->remove($address);
        $this->em->flush();
        // Keep exactly one default address when possible.
        if ($wasDefault && null !== ($next = $this->addresses->findDefaultForUser($this->buyer()))) {
            $next->setIsDefault(true);
            $this->em->flush();
        }

        $this->audit->log(AuditLog::ADDRESS_DELETED, ['address' => $uuid]);
        $this->addFlash('success', 'Address deleted. Past orders keep their own copy of the address.');

        return $this->redirectToRoute('buyer_addresses');
    }

    /**
     * Stops the request when the hidden "_token" field is not the token of this session.
     *
     * @param string $id name of the token (one per action and per address)
     */
    private function assertCsrf(Request $request, string $id): void
    {
        // SECURITY (rule 3).
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** Returns the logged-in user as a User object (or stops with "access denied"). */
    private function buyer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
