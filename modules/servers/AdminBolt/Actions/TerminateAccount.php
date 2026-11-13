<?php

namespace ModulesGarden\AdminBolt\Actions;

use Exception;

class TerminateAccount extends AbstractAction
{
    /**
     * Deletes every hosting account of the reseller through the regular
     * account deletion endpoint, then the reseller itself. Stops at the first
     * account that cannot be deleted and keeps the reseller, so the command
     * can be run again once the cause is fixed.
     */
    public function resellerExecute(): string
    {
        $resellerId = $this->getVerifiedResellerId();

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

        \logModuleCall('AdminBolt', 'TerminateAccount', 'reseller #' . $resellerId, $summary, $summary);

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
