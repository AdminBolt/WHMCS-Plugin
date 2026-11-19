<?php

namespace ModulesGarden\AdminBolt\Actions;

use Exception;
use ModulesGarden\AdminBolt\Api\ApiException;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;

class TerminateAccount extends AbstractAction
{
    /**
     * The panel answers the terminate call at once, unless another
     * operation holds the reseller: it then waits up to two minutes before
     * it answers 409. A little more than that, well below the WHMCS limit.
     */
    public const QUEUE_TIMEOUT_SECONDS = 150;

    /**
     * Asks the panel to terminate the reseller: the panel blocks it at once
     * and deletes its hosting accounts, its plans and the reseller in a
     * background job, so the command returns in a moment however many
     * accounts the reseller has. A call while the panel is still at it joins
     * that job. A panel without the terminate endpoint (before AdminBolt
     * 1.9.7) gets the accounts deleted one by one from here, as before.
     */
    public function resellerExecute(): string
    {
        $resellerId = $this->getVerifiedResellerId();

        $api = $this->getApiInstance();

        try
        {
            $response = $api->withTimeout(self::QUEUE_TIMEOUT_SECONDS)->post('/api/resellers/' . $resellerId . '/terminate');
        }
        catch(ApiException $e)
        {
            if(!$this->isMissingEndpoint($e))
            {
                throw new Exception($e->getPanelReason(), 0, $e);
            }

            return $this->terminateAccountByAccount($resellerId);
        }

        ModuleLog::call('TerminateAccount', 'reseller #' . $resellerId, $this->describeQueuedTermination($resellerId, is_array($response) ? $response : []));

        return 'success';
    }

    /**
     * The panel answered 404 for the terminate endpoint itself, not for the
     * reseller (checked just before): an older panel without it.
     */
    private function isMissingEndpoint(ApiException $e): bool
    {
        if($e->getHttpCode() === 405)
        {
            return true;
        }

        $message = (string) (($e->getResponseBody() ?? [])['message'] ?? '');

        return $e->getHttpCode() === 404 && stripos($message, 'Reseller not found') === false;
    }

    /**
     * The Module Log line of a queued termination: what the panel has done so
     * far and what failed in an earlier run, with the reasons.
     */
    private function describeQueuedTermination(int $resellerId, array $response): string
    {
        $state = (string) ($response['termination_state'] ?? '');
        $summary = sprintf(
            'Termination of reseller #%d %s; the panel deletes its hosting accounts, its plans and the reseller in the background (Background Jobs page).',
            $resellerId,
            match($state) {
                'running' => 'already in progress',
                'delayed' => 'recorded, its background job is queued by the panel within a few minutes',
                default => 'queued',
            }
        );

        $deleted = array_filter((array) ($response['accounts_deleted'] ?? []), 'is_string');
        $pending = array_filter((array) ($response['accounts_pending'] ?? []), 'is_string');
        $summary .= sprintf(' Accounts deleted so far: %d; still to delete: %d%s.', count($deleted), count($pending), $pending ? ' (' . implode(', ', $pending) . ')' : '');

        $failed = [];
        foreach((array) ($response['accounts_failed'] ?? []) as $failure)
        {
            if(is_array($failure))
            {
                $failed[] = (is_string($failure['username'] ?? null) ? $failure['username'] : 'the reseller') . ': ' . (string) ($failure['reason'] ?? '');
            }
        }

        if($failed)
        {
            $summary .= ' Not deleted in the last run, tried again now: ' . implode('; ', $failed) . '.';
        }

        $leftovers = [];
        foreach((array) ($response['accounts_deleted_with_leftovers'] ?? []) as $leftover)
        {
            if(is_array($leftover) && is_string($leftover['username'] ?? null))
            {
                $leftovers[] = $leftover['username'] . ': ' . (string) ($leftover['reason'] ?? '');
            }
        }

        if($leftovers)
        {
            $summary .= ' Accounts deleted with unfinished cleanup steps: ' . implode(' ', $leftovers);
        }

        return $summary;
    }

    /**
     * Before AdminBolt 1.9.7: every hosting account of the reseller through
     * the regular account deletion endpoint, then the reseller itself. Stops
     * at the first account that cannot be deleted and keeps the reseller, so
     * the command can be run again once the cause is fixed.
     */
    private function terminateAccountByAccount(int $resellerId): string
    {
        $api = $this->getApiInstance();

        $accounts = [];
        foreach($this->extractList($api->get('/api/hosting-accounts')) as $account)
        {
            if(is_array($account) && isset($account['id']) && (int) ($account['reseller_id'] ?? 0) === $resellerId)
            {
                $accounts[] = $account;
            }
        }

        $deleted = [];
        $warnings = [];

        foreach($accounts as $account)
        {
            $accountId = (int) $account['id'];

            try
            {
                $response = $api->delete('/api/hosting-accounts/' . $accountId);
            }
            catch(Exception $e)
            {
                throw new Exception(sprintf(
                    'Hosting account #%d (%s) of reseller #%d could not be deleted: %s. The reseller was not deleted; %d of %d accounts were deleted. Fix the cause and run Terminate again.',
                    $accountId,
                    $this->describeAccount($account),
                    $resellerId,
                    rtrim($e->getMessage(), '. '),
                    count($deleted),
                    count($accounts)
                ), 0, $e);
            }

            $deleted[] = $accountId;

            if(is_array($response) && ($response['status'] ?? '') === 'warning')
            {
                $warnings[] = sprintf('#%d: %s', $accountId, (string) ($response['message'] ?? ''));
            }
        }

        $api->delete('/api/resellers/' . $resellerId);

        $summary = sprintf(
            'Reseller #%d deleted with %d hosting account(s)%s.',
            $resellerId,
            count($deleted),
            $deleted ? ' (#' . implode(', #', $deleted) . ')' : ''
        );

        if($warnings)
        {
            $summary .= ' Accounts deleted with unfinished cleanup steps: ' . implode(' ', $warnings);
        }

        ModuleLog::call('TerminateAccount', 'reseller #' . $resellerId, $summary);

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getVerifiedHostingAccountId();

        $api = $this->getApiInstance();
        $api->delete('/api/hosting-accounts/' . $hostingAccountId);

        return 'success';
    }

    private function describeAccount(array $account): string
    {
        $parts = array_filter([
            (string) ($account['username'] ?? ''),
            (string) ($account['domain'] ?? ''),
        ], static fn(string $value): bool => $value !== '');

        return $parts ? implode(', ', $parts) : 'no username';
    }
}
