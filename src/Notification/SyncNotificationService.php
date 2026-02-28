<?php

namespace App\Notification;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Event\SyncCompletedEvent;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsEventListener(event: SyncCompletedEvent::class)]
class SyncNotificationService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCompletedEvent $event): void
    {
        $syncRun = $event->syncRun;
        $recipients = $this->resolveRecipients($syncRun);

        foreach ($recipients as $user) {
            $this->sendNotification($user, $syncRun);
        }
    }

    /**
     * Determines which users should be notified based on their preferences
     * and the sync outcome.
     *
     * @return User[]
     */
    private function resolveRecipients(SyncRun $syncRun): array
    {
        $allUsers = $this->userRepository->findAll();

        return array_filter($allUsers, static function (User $user) use ($syncRun): bool {
            // Only notify verified users
            if (!$user->isVerified()) {
                return false;
            }

            // Failure notifications
            if ($syncRun->getStatus() === 'failed' && $user->isNotifyOnFailure()) {
                return true;
            }

            // Success with changes
            if ($syncRun->getStatus() === 'success') {
                $hasChanges = ($syncRun->getAddedCount() ?? 0) > 0
                    || ($syncRun->getRemovedCount() ?? 0) > 0;

                if ($hasChanges && $user->isNotifyOnSuccess()) {
                    return true;
                }

                // Success with no changes
                if (!$hasChanges && $user->isNotifyOnNoChanges()) {
                    return true;
                }
            }

            return false;
        });
    }

    private function sendNotification(User $user, SyncRun $syncRun): void
    {
        $html = $this->twig->render('email/sync_notification.html.twig', [
            'user' => $user,
            'syncRun' => $syncRun,
        ]);

        $statusLabel = $syncRun->getStatus() === 'failed' ? "\u{274C} Failed" : "\u{2705} Completed";

        $email = (new Email())
            ->to($user->getEmail())
            ->subject(sprintf(
                'Sync %s — %s',
                $statusLabel,
                $syncRun->getSyncList()->getName(),
            ))
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send sync notification to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
