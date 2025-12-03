<?php

namespace ModulesGarden\AdminBolt\Actions;

use WHMCS\Database\Capsule;
use Exception;

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
            'phone' => '+' . $this->params['clientsdetails']['phonecc'] + $this->params['clientsdetails']['phonenumber'],
            'address' => $this->params['clientsdetails']['address1'],
            'city' => $this->params['clientsdetails']['city'],
            'state' => $this->params['clientsdetails']['state'],
            'zip' => $this->params['clientsdetails']['postcode'],
            'country' => $this->params['clientsdetails']['countrycode'],
            'company' => $this->params['clientsdetails']['company'],
        ]);

        $this->updateCustomFieldValue('resellerId', $result['id']);

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
            'phone' => '+' . $this->params['clientsdetails']['phonecc'] + $this->params['clientsdetails']['phonenumber'],
            'address' => $this->params['clientsdetails']['address1'],
            'city' => $this->params['clientsdetails']['city'],
            'state' => $this->params['clientsdetails']['state'],
            'zip' => $this->params['clientsdetails']['postcode'],
            'country' => $this->params['clientsdetails']['countrycode'],
            'company' => $this->params['clientsdetails']['company']
        ]);

        $this->updateCustomFieldValue('hostingAccountId', $result['hostingAccount']['id']);

        return 'success';
    }

    protected function updateCustomFieldValue(string $customFieldName, string $value): void
    {
        $customFieldValue = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.type', '=', 'product')
            ->where('tblcustomfields.relid', '=', $this->params['packageid'])
            ->where('tblcustomfieldsvalues.relid', '=', $this->params['serviceid'])
            ->where('tblcustomfields.fieldname', 'LIKE', "$customFieldName|%")
            ->first(['tblcustomfieldsvalues.id']);

        if($customFieldValue)
        {
            Capsule::table('tblcustomfieldsvalues')
                ->where('id', '=', $customFieldValue->id)
                ->update([
                    'value' => $value
                ]);
        }
        else
        {
            $customField = Capsule::table('tblcustomfields')
                ->where('type', '=', 'product')
                ->where('relid', '=', $this->params['packageid'])
                ->where('fieldname', 'LIKE', "$customFieldName|%")
                ->first([
                    'id'
                ]);

            if(!$customField)
            {
                throw new Exception("Custom field $customFieldName does not exist");
            }

            Capsule::table('tblcustomfieldsvalues')
                ->insert([
                    'fieldid' => $customField->id,
                    'relid' => $this->params['serviceid'],
                    'value' => $value
                ]);
        }

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
        $username = $this->params['username'];

        if(empty($username))
        {
            $username = $this->generateUsername();
            $this->updateUsername($username);
        }

        return $username;
    }
}