<?php

namespace App\Controller;

use App\Form\SetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $userId = $request->query->get('id');

        if ($userId === null || $userId === '') {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'Invalid verification link. No user ID provided.',
            ]);
        }

        $user = $this->userRepository->find($userId);

        if ($user === null) {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'Invalid verification link. User not found.',
            ]);
        }

        if ($user->isVerified()) {
            $this->addFlash(
                'info',
                'Your account is already verified. Please log in.',
            );

            return $this->redirectToRoute('app_login');
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
                $request,
                (string) $user->getId(),
                $user->getEmail(),
            );
        } catch (VerifyEmailExceptionInterface) {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'This verification link has expired or is invalid. Please ask your administrator to resend the invitation.',
            ]);
        }

        // The link is valid — show the "set password" form
        $form = $this->createForm(SetPasswordType::class);

        // Pre-fill the hidden token field with the full signed URI so we can re-validate on submit
        $form->get('token')->setData($request->getUri());

        return $this->render('verify_email/verify.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route(
        '/verify-email/complete',
        name: 'app_verify_email_complete',
        methods: ['POST'],
    ),]
    public function complete(Request $request): Response
    {
        $form = $this->createForm(SetPasswordType::class);
        $form->handleRequest($request);

        // Extract the signed URL from the hidden token field
        $signedUrl = $form->get('token')->getData();

        if ($signedUrl === null || $signedUrl === '') {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'Invalid request. Missing verification token.',
            ]);
        }

        // Parse the user ID from the signed URL
        $parsedUrl = parse_url($signedUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $userId = $queryParams['id'] ?? null;

        if ($userId === null) {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'Invalid verification token.',
            ]);
        }

        $user = $this->userRepository->find($userId);

        if ($user === null) {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'User not found.',
            ]);
        }

        if ($user->isVerified()) {
            $this->addFlash(
                'info',
                'Your account is already verified. Please log in.',
            );

            return $this->redirectToRoute('app_login');
        }

        // Re-validate the signed URL to prevent replay attacks
        $signedRequest = Request::create($signedUrl);

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
                $signedRequest,
                (string) $user->getId(),
                $user->getEmail(),
            );
        } catch (VerifyEmailExceptionInterface) {
            return $this->render('verify_email/expired.html.twig', [
                'message' => 'This verification link has expired or is invalid. Please ask your administrator to resend the invitation.',
            ]);
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Re-render the form with validation errors
            $form->get('token')->setData($signedUrl);

            return $this->render('verify_email/verify.html.twig', [
                'user' => $user,
                'form' => $form,
            ]);
        }

        // Hash the password and activate the account
        $plainPassword = $form->get('plainPassword')->getData();
        $hashedPassword = $this->passwordHasher->hashPassword(
            $user,
            $plainPassword,
        );

        $user->setPassword($hashedPassword);
        $user->setIsVerified(true);
        $this->entityManager->flush();

        $this->addFlash('success', 'Your account is ready. Please log in.');

        return $this->redirectToRoute('app_login');
    }
}
