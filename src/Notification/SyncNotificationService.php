<?php

namespace App\Notification;

use App\Entity\SyncRun;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SyncNotificationService
{
    /**
     * Results from the most recent notification dispatch.
     *
     * @var list<array{email: string, success: bool, error: ?string}>
     */
    private array $lastResults = [];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends a single summary notification email per recipient for a batch of sync runs.
     *
     * @param SyncRun[] $syncRuns
     */
    public function sendBatchNotification(array $syncRuns): void
    {
        $this->lastResults = [];

        if ($syncRuns === []) {
            return;
        }

        $recipients = $this->resolveRecipients($syncRuns);

        foreach ($recipients as $user) {
            $this->sendNotification($user, $syncRuns);
        }
    }

    /**
     * Returns results from the most recent notification dispatch.
     *
     * @return list<array{email: string, success: bool, error: ?string}>
     */
    public function getLastResults(): array
    {
        return $this->lastResults;
    }

    /**
     * Determines which users should be notified based on their preferences
     * and the batch of sync runs.
     *
     * A user receives the email if ANY run in the batch matches their preferences.
     *
     * @param SyncRun[] $syncRuns
     *
     * @return User[]
     */
    private function resolveRecipients(array $syncRuns): array
    {
        $allUsers = $this->userRepository->findAll();

        $hasFailure = false;
        $hasSuccessWithChanges = false;
        $hasSuccessWithoutChanges = false;

        foreach ($syncRuns as $syncRun) {
            if ($syncRun->getStatus() === 'failed') {
                $hasFailure = true;
            } elseif ($syncRun->getStatus() === 'success') {
                $hasChanges = ($syncRun->getAddedCount() ?? 0) > 0
                    || ($syncRun->getRemovedCount() ?? 0) > 0;

                if ($hasChanges) {
                    $hasSuccessWithChanges = true;
                } else {
                    $hasSuccessWithoutChanges = true;
                }
            }
        }

        return array_values(array_filter($allUsers, static function (User $user) use (
            $hasFailure,
            $hasSuccessWithChanges,
            $hasSuccessWithoutChanges,
        ): bool {
            if (!$user->isVerified()) {
                return false;
            }

            if ($hasFailure && $user->isNotifyOnFailure()) {
                return true;
            }

            if ($hasSuccessWithChanges && $user->isNotifyOnSuccess()) {
                return true;
            }

            if ($hasSuccessWithoutChanges && $user->isNotifyOnNoChanges()) {
                return true;
            }

            return false;
        }));
    }

    /**
     * @param SyncRun[] $syncRuns
     */
    private function sendNotification(User $user, array $syncRuns): void
    {
        $html = $this->twig->render('email/sync_notification.html.twig', [
            'user' => $user,
            'syncRuns' => $syncRuns,
        ]);

        $hasFailure = false;
        $hasSuccess = false;

        foreach ($syncRuns as $run) {
            if ($run->getStatus() === 'failed') {
                $hasFailure = true;
            } else {
                $hasSuccess = true;
            }
        }

        if ($hasFailure && $hasSuccess) {
            $statusLabel = "\u{26A0}\u{FE0F} Partial Failure";
        } elseif ($hasFailure) {
            $statusLabel = "\u{274C} Failed";
        } else {
            $statusLabel = "\u{2705} Completed";
        }

        $listCount = count($syncRuns);
        $subject = $listCount === 1
            ? sprintf('Sync %s — %s', $statusLabel, $syncRuns[0]->getSyncList()->getName())
            : sprintf('Sync %s — %d lists', $statusLabel, $listCount);

        $email = (new Email())
            ->to($user->getEmail())
            ->subject($subject)
            ->html($html);

        try {
            $this->mailer->send($email);
            $this->lastResults[] = ['email' => $user->getEmail(), 'success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->lastResults[] = ['email' => $user->getEmail(), 'success' => false, 'error' => $e->getMessage()];
            $this->logger->error('Failed to send sync notification to {email}: {error}', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
