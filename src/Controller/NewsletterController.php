<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\NewsletterSubscriber;
use App\Repository\NewsletterSubscriberRepository;
use App\Security\SafeRedirect;
use App\Service\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Newsletter sign-up (home page block and footer).
 */
class NewsletterController extends AbstractController
{
    #[Route('/newsletter', name: 'newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        NewsletterSubscriberRepository $subscribers,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        RateLimiterFactoryInterface $newsletterLimiter,
        AuditLogger $audit,
    ): RedirectResponse {
        // SECURITY (rule 12): only an internal path can be used as the return target.
        $back = $request->request->getString('_back');
        $target = SafeRedirect::isSafePath($back) ? $back : $this->generateUrl('app_home');
        $target = strtok($target, '#').'#newsletter';

        // SECURITY (rule 3): CSRF token, so another site can't subscribe our visitors.
        if (!$this->isCsrfTokenValid('newsletter', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // SECURITY (rule 6): per-IP rate limit (stops mass sign-ups of third-party addresses / DB flooding).
        if (!$newsletterLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $audit->log(AuditLog::RATE_LIMITED, ['limiter' => 'newsletter']);
            $this->addFlash('error', 'Too many requests. Please try again later.');

            return $this->redirect($target);
        }

        $email = mb_strtolower(trim($request->request->getString('email')));
        // SECURITY (rule 15): strict email validation and length cap.
        $errors = $validator->validate($email, [new Assert\NotBlank(), new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT), new Assert\Length(max: 180)]);
        if (count($errors) > 0) {
            $this->addFlash('error', 'Please enter a valid email address.');

            return $this->redirect($target);
        }

        if (null === $subscribers->findOneBy(['email' => $email])) {
            try {
                $em->persist(new NewsletterSubscriber($email));
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Concurrent duplicate: same outcome for the visitor.
            }
        }

        // SECURITY: the same message whether the address was already subscribed or not (no enumeration).
        $this->addFlash('success', 'Thank you! You are on the list for new arrivals and private sales.');

        return $this->redirect($target);
    }
}
