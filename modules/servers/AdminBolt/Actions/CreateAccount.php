<?php

namespace ModulesGarden\AdminBolt\Actions;

use WHMCS\Database\Capsule;

class CreateAccount extends AbstractAction
{
    public function resellerExecute(): string
    {
        $api = $this->getApiInstance();
        $result = $api->post('/api/resellers', [
            'name' => $this->params['clientsdetails']['fullname'],
            'username' => $this->getUsername(),
            'password' => $this->params['password'],
            'email' => $this->params['clientsdetails']['email'],
            'phone' => $this->formatPhone(),
            'address' => $this->params['clientsdetails']['address1'],
            'city' => $this->params['clientsdetails']['city'],
            'state' => $this->params['clientsdetails']['state'],
            'zip' => $this->params['clientsdetails']['postcode'],
            'country' => $this->params['clientsdetails']['countrycode'],
            'company' => $this->params['clientsdetails']['company'],
        ]);

        $resellerId = $this->extractId($result);

        if($resellerId > 0)
        {
            $this->saveCustomFieldValue('resellerId', $resellerId);
        }

        return 'success';
    }

    public function sharedExecute(): string
    {
        $api = $this->getApiInstance();
        $result = $api->post('/api/hosting-accounts', [
            'domain' => $this->params['domain'],
            'hosting_plan_id' => $this->params['configoption1'],
            'username' => $this->getUsername(),
            'ssh_access' => $this->params['configoption2'] == 'on',
            'password' => $this->params['password'],
            'is_suspended' => false,
            'name' => $this->params['clientsdetails']['fullname'],
            'phone' => $this->formatPhone(),
            'address' => $this->params['clientsdetails']['address1'],
            'city' => $this->params['clientsdetails']['city'],
            'state' => $this->params['clientsdetails']['state'],
            'zip' => $this->params['clientsdetails']['postcode'],
            'country' => $this->params['clientsdetails']['countrycode'],
            'company' => $this->params['clientsdetails']['company']
        ]);

        $hostingAccountId = $this->extractId($result);

        if($hostingAccountId > 0)
        {
            $this->saveCustomFieldValue('hostingAccountId', $hostingAccountId);
        }

        return 'success';
    }

    protected function extractId(mixed $response): int
    {
        if(!is_array($response))
        {
            return 0;
        }

        if(isset($response['id']))
        {
            return (int) $response['id'];
        }

        foreach(['hostingAccount', 'hosting_account', 'reseller', 'data'] as $key)
        {
            if(isset($response[$key]) && is_array($response[$key]) && isset($response[$key]['id']))
            {
                return (int) $response[$key]['id'];
            }
        }

        return 0;
    }

    protected function formatPhone(): string
    {
        $cc = trim((string) ($this->params['clientsdetails']['phonecc'] ?? ''));
        $number = trim((string) ($this->params['clientsdetails']['phonenumber'] ?? ''));

        if($cc === '' && $number === '')
        {
            return '';
        }

        return '+' . $cc . $number;
    }

    protected function updateUsername(string $username): void
    {
        Capsule::table('tblhosting')
            ->where('id', '=', $this->params['serviceid'])
            ->update([
                'username' => $username
            ]);
    }

    protected function generateUsername(): string
    {
        $chars = "abcdefghijklmnopqrstuvwxyz";
        $length = random_int(8, 10);

        $username = "";

        for($i = 0; $i < $length; $i++)
        {
            $username .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $username;
    }

    protected function getUsername(): string
    {
        $username = $this->params['username'] ?? '';

        if(empty($username))
        {
            $username = $this->generateUsername();
            $this->updateUsername($username);
            $this->params['username'] = $username;
        }

        return $username;
    }
}
