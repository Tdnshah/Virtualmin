<?php
/**
 * Copyright 2022-2023 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
/**
 * @see http://doxfer.com/Webmin/TheWebminAPI
 */
class Server_Manager_Virtualmin extends Server_Manager
{
    public function init()
    {
        if (empty($this->_config['port'])) {
            $this->_config['port'] = 10000;
        }
    }

    public static function getForm()
    {
        return array(
            'label'     =>  'Virtualmin',
        );
    }

    public function getLoginUrl(Server_Account $account = null)
    {
        if ($this->_config['secure']) {
            return 'https://'.$this->_config['host'] . ':' . $this->_config['port'] . '/';
        } else {
            return 'http://'.$this->_config['host'] . ':' . $this->_config['port'] . '/';
        }
    }

    public function getResellerLoginUrl(Server_Account $account = null)
    {
        return $this->getLoginUrl();
    }

    public function testConnection()
    {
        $result = $this->_makeRequest('list-commands');

        if (isset($result['status']) && $result['status'] == 'success') {
            return true;
        } else {
            throw new Server_Exception('Failed to connect to the :type: server. Please verify your credentials and configuration', [':type:' => 'Virtualmin']);
        }
    }

    public function synchronizeAccount(Server_Account $a)
    {
        $this->getLog()->info('Synchronizing account with server '.$a->getUsername());
        return $a;
    }

    public function createAccount(Server_Account $a)
    {
        try {
            if ($a->getReseller()) {
                if (!$this->_createReseller($a)) {
                    return false;
                }
            } else {
                if (!$this->_createUser($a)) {
                    return false;
                }
            }
        } catch (Exception $e) {
            if (!str_contains(strtolower($e->getMessage()), strtolower('You are already hosting this domain'))) {
                throw new Server_Exception($e->getMessage());
            } else {
                return true;
            }
        }

        return true;
    }

    public function suspendAccount(Server_Account $a)
    {
        if ($a->getReseller()) {
            throw new Server_Exception('Virtualmin can\'t suspend/unsuspend reseller\'s account');
        } else {
            if (!$this->_suspendUser($a)) {
                return false;
            }
        }

        return true;
    }

    public function unsuspendAccount(Server_Account $a)
    {
        if ($a->getReseller()) {
            throw new Server_Exception('Virtualmin can\'t suspend/unsuspend reseller\'s account');
        } else {
            if (!$this->_unsuspendUser($a)) {
                return false;
            }
        }

        return true;
    }

    public function cancelAccount(Server_Account $a)
    {
        if ($a->getReseller()) {
            if (!$this->_cancelReseller($a)) {
                return false;
            }
        } else {
            if (!$this->_cancelUser($a)) {
                return false;
            }
        }

        return true;
    }

    public function changeAccountPackage(Server_Account $a, Server_Package $p)
    {
        if ($a->getReseller()) {
            if (!$this->_modifyReseller($a)) {
                return false;
            }
        } else {
            if (!$this->_modifyDomain($a)) {
                return false;
            }
            if (!$this->_disableFeatures($a)) {
                return false;
            }
            if (!$this->_enableFeatures($a)) {
                return false;
            }
        }

        return true;
    }

    public function changeAccountPassword(Server_Account $a, $newPassword)
    {
        if ($a->getReseller()) {
            if (!$this->_changeResellerPassword($a, $this->_encodePassword($newPassword))) {
                return false;
            }
        } else {
            if (!$this->_changeUserPassword($a, $this->_encodePassword($newPassword))) {
                return false;
            }
        }

        return true;
    }

    public function changeAccountUsername(Server_Account $a, $new)
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Virtualmin', ':action:' => __trans('changing the account IP')]);
    }

    public function changeAccountDomain(Server_Account $a, $new)
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Virtualmin', ':action:' => __trans('changing the account IP')]);
    }

    public function changeAccountIp(Server_Account $a, $new)
    {
        throw new Server_Exception(':type: does not support :action:', [':type:' => 'Virtualmin', ':action:' => __trans('changing the account IP')]);
    }

    /**
     *
     * Makes request to virtualmin server
     * @param string $command
     * @param array $params
     * @param string $format
     * @return array
     */
    private function _makeRequest($command, $params = array(), $format = 'json')
    {
        $url = $this->_getUrl() . '?program=' . $command . '&' . $format . '=1';

        $numbers = array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9);
        foreach ($params as $key => $param) {
            $key = str_replace($numbers, '', $key);
            $url .= '&' . $key . '=' . $param;
        }

        $username = $this->_config['username'];
        $password = $this->_config['password'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);

        if (isset($json['full_error'])) {
            throw new Server_Exception($json['full_error']);
        }
        return $json;
    }

    /**
     *
     * Forms server url
     * @return string
     */
    private function _getUrl()
    {
        $url = (isset($this->_config['secure']) && $this->_config['secure'])  ? 'https://' : 'http://';
        $url .= $this->_config['host'] . ':' . $this->_config['port'] . '/virtual-server/remote.cgi';

        return $url;
    }

    /**
     *
     * Parses html to extract error. If string is not html returns same string
     * @param string $result
     * @return string
     */
    private function _extractError($result)
    {
        $html = new DOMDocument();
        try {
            $html->loadHTML($result);
        } catch (Exception) {
            return $result;
        }

        $h1 = $html->getElementsByTagName('h1')
                   ->item(0);

        if (!isset($h1->nodeValue)) {
            return $result;
        }

        $error = $h1->nodeValue;

        $body = $html->getElementsByTagName('body')
                     ->item(0);

        $body->removeChild($h1);

        if (!empty($body->nodeValue)) {
            $error .= ' - ' . $body->nodeValue;
        }

        return $error;
    }

    /**
     *
     * Creates reseller
     * @throws Server_Exception
     * @return boolean
     */
    private function _createReseller(Server_Account $a)
    {
        if (!$this->_checkCommand('create-reseller')) {
            throw new Server_Exception('Create reseller command is only available in Virtualmin PRO version');
        }

        $p = $a->getPackage();
        $client = $a->getClient();
        $params = array(
            'name'			=>	$a->getUsername(),
            'pass'			=>	$a->getPassword(),
            'email'			=>	$client->getEmail(),
            'max-doms'		=>	($p->getMaxDomains() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxDomains(),
            'max-aliasdoms'	=>	($p->getMaxDomains() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxDomains(),
            'max-realdoms'	=>	($p->getMaxDomains() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxDomains(),
            'max-quota'		=>	($p->getQuota() == 'unlimited') ? 'UNLIMITED' : (int)$p->getQuota() * 1024,
            'max-mailboxes'	=>	(int)$p->getMaxPop(),
            'max-aliases'	=>	(int)$p->getMaxDomains() ? $p->getMaxDomains() : 1,
            'max-dbs'		=>	($p->getMaxSql() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxSql(),
            'max-bw'		=>	($p->getBandwidth() == 'unlimited') ? 'UNLIMITED' : (int)$p->getBandwidth() * 1024 * 1024,
            'allow1'		=>	'dns',		//BIND DNS domain
            'allow2'		=>	'web',		//Apache website
            'allow3'		=>	'webmin',	//Webmin login
            'allow4'		=>	'dir',		//Home directory
            'allow5'		=>	'virt',		//Virtual IP address
            'nameserver1'	=>	$a->getNs1(),
            'nameserver2'	=>	$a->getNs2(),
            'nameserver3'	=>	$a->getNs3(),
            'nameserver4'	=>	$a->getNs4(),
        );
        if ($p->getMaxPop()) {
            $params['allow6'] = 'mail';
        }
        if ($p->getMaxFtp() > 0) {
            $params['allow7'] = 'ftp';
        }
        if ($p->getMaxSql() > 0) {
            $params['allow8'] = 'mysql';
        }

        $response = $this->_makeRequest('create-reseller', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('create reseller account'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Get available commands list
     */
    private function _getCommands()
    {
        $response = $this->_makeRequest('list-commands');

        return $response['output'];
    }

    /**
     *
     * Checks if command is available
     * @param string $command
     * @return boolean
     */
    private function _checkCommand($command)
    {
        $commands = $this->_getCommands();

        if (!str_contains($commands, $command)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     *
     * Creates user's account
     * @throws Server_Exception
     * @returns boolean
     */
    private function _createUser(Server_Account $a)
    {
        $p = $a->getPackage();
        $client = $a->getClient();
        $params = array(
            'domain'			=>	$a->getDomain(),
            'pass'				=>	$a->getPassword(),
            'email'				=>	$client->getEmail(),
            'user'				=>	$a->getUsername(),
            'dns'				=>	'',
            'web'				=>	'',
            'webmin'			=>	'',
            'max-doms'			=>	(int)$p->getMaxDomains() ? $p->getMaxDomains() : 1,
            'max-aliasdoms' 	=>	(int)$p->getMaxDomains() ? $p->getMaxDomains() : 1,
            'max-realdoms' 		=>	(int)$p->getMaxDomains() ? $p->getMaxDomains() : 1,
            'max-mailboxes'		=>	(int)$p->getMaxPop() ? $p->getMaxPop() : 1,
            'unix'				=>	'',
            'dir'				=>	'',
            'quota'				=>	($p->getQuota() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxQuota(),
            'uquota'			=>	($p->getQuota() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxQuota(),
            'bandwidth'			=>	($p->getBandwidth() == 'unlimited') ? 'UNLIMITED' : (int)$p->getBandwidth() * 1024 * 1024,
            'mysql-pass'		=>	$this->_encodePassword($a->getPassword()),
        );
        if ($p->getMaxPop()) {
            $params['mail'] = '';
        }
        if ($p->getMaxFtp() > 0) {
            $params['ftp'] = '';
        }
        if ($p->getMaxSql() > 0) {
            $params['mysql'] = '';
        }
        if (!$a->getIp()) {
            $params['allocate-ip'] = '';
        } else {
            $params['shared-ip'] = $a->getIp();
            $params['ip-already'] = '';
        }

        $response = $this->_makeRequest('create-domain', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('create account'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Suspends user's account
     * @throws Server_Exception
     * @return boolean
     */
    private function _suspendUser(Server_Account $a)
    {
        $params = array(
            'domain'	=>	$a->getDomain(),
        );

        $response = $this->_makeRequest('disable-domain', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('suspend account'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Unsuspends user's account
     * @throws Server_Exception
     * @return booblean
     */
    private function _unsuspendUser(Server_Account $a)
    {
        $params = array(
            'domain'	=>	$a->getDomain(),
        );

        $response = $this->_makeRequest('enable-domain', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('unsuspend account'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Changes user's password
     * @throws Server_Exception
     * @return boolean
     */
    private function _changeUserPassword(Server_Account $a, $newPassword)
    {
        $params = array(
            'domain'	=>	$a->getDomain(),
            'pass'		=>	$newPassword,
        );

        $response = $this->_makeRequest('modify-domain', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('change account password'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Cancels user's account
     * @throws Server_Exception
     * @return boolean
     */
    private function _cancelUser(Server_Account $a)
    {
        $params = array(
            'domain'	=>	$a->getDomain(),
        );

        $response = $this->_makeRequest('delete-domain', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('cancel account'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Modifies domain
     * @throws Server_Exception
     * @return boolean
     */
    private function _modifyDomain(Server_Account $a)
    {
        $p = $a->getPackage();
        $client = $a->getClient();
        $params = array(
            'domain'	=>	$a->getDomain(),
            'pass'		=>	$this->_encodePassword($a->getPassword()),
            'email'		=>	$client->getEmail(),
            'quota'		=>	($p->getQuota() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxQuota(),
            'uquota'	=>	($p->getQuota() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxQuota(),
            'bw'		=>	($p->getBandwidth() == 'unlimited') ? 'UNLIMITED' : (int)$p->getBandwidth(),
        );

        $response = $this->_makeRequest('modify-domain', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('modify domain details'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Enables features for user
     * @throws Server_Exception
     * @return boolean
     */
    private function _enableFeatures(Server_Account $a)
    {
        $p = $a->getPackage();
        $params = array(
            'domain'	=>	$a->getDomain(),
        );

        if ($p->getMaxPop() > 0) {
            $params['mail'] = '';
        }
        if ($p->getMaxSql() > 0) {
            $params['mysql'] = '';
        }
        if ($p->getMaxFtp() > 0) {
            $params['ftp'] = '';
        }

        $response = $this->_makeRequest('enable-feature', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('enable features'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     *
     * Disable not needed features for user
     * @throws Server_Exception
     * @return boolean
     */
    private function _disableFeatures(Server_Account $a)
    {
        $p = $a->getPackage();
        $params = array(
            'domain'	=>	$a->getDomain(),
        );

        if (!$p->getMaxPop() == 0) {
            $params['mail'] = '';
        }
        if ($p->getMaxSql() == 0) {
            $params['mysql'] = '';
        }
        if ($p->getMaxFtp() == 0) {
            $params['ftp'] = '';
        }

        $response = $this->_makeRequest('disable-feature', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('disable features'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    private function _cancelReseller(Server_Account $a)
    {
        if (!$this->_checkCommand('create-reseller')) {
            throw new Server_Exception('Cancel reseller command only available in Virtualmin PRO version');
        }
        $params = array(
            'name'	=>	$a->getUsername(),
        );

        $response = $this->_makeRequest('delete-reseller', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            throw new Server_Exception('Failed to delete reseller');
        }
    }

    private function _changeResellerPassword(Server_Account $a, $newPassword)
    {
        if (!$this->_checkCommand('modify-reseller')) {
            throw new Server_Exception('Modify reseller comand is only available in Virtualmin PRO version');
        }
        $params = array(
            'name'	=>	$a->getUsername(),
            'pass'	=>	$this->_encodePassword($newPassword),
        );

        $response = $this->_makeRequest('modify-reseller', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            throw new Server_Exception('Failed to change reseller\'s password');
        }
    }

    private function _modifyReseller(Server_Account $a)
    {
        if (!$this->_checkCommand('modify-reseller')) {
            throw new Server_Exception('Modify reseller command is only available in Virtualmin PRO version');
        }

        $p = $a->getPackage();
        $client = $a->getClient();
        $params = array(
            'name'			=>	$a->getUsername(),
            'pass'			=>	$this->_encodePassword($a->getPassword()),
            'email'			=>	$client->getEmail(),
            'max-doms'		=>	($p->getMaxDomains() == 'unlimited') ? 'UNLIMITED' : $p->getMaxDomains(),
            'max-aliasdoms'	=>	($p->getMaxDomains() == 'unlimited') ? 'UNLIMITED' : $p->getMaxDomains(),
            'max-realdoms'	=>	($p->getMaxDomains() == 'unlimited') ? 'UNLIMITED' : $p->getMaxDomains(),
            'max-quota'		=>	($p->getQuota() == 'unlimited') ? 'UNLIMITED' : (int)$p->getQuota() * 1024,
            'max-mailboxes'	=>	(int)$p->getMaxPop(),
            'max-aliases'	=>	(int)$p->getMaxDomains() ? $p->getMaxDomains() : 1,
            'max-dbs'		=>	($p->getMaxSql() == 'unlimited') ? 'UNLIMITED' : (int)$p->getMaxSql(),
            'max-bw'		=>	($p->getBandwidth() == 'unlimited') ? 'UNLIMITED' : (int)$p->getBandwidth() * 1024 * 1024,
            'allow1'		=>	'dns',		//BIND DNS domain
            'allow2'		=>	'web',		//Apache website
            'allow3'		=>	'webmin',	//Webmin login
            'allow4'		=>	'dir',		//Home directory
            'allow5'		=>	'virt',		//Virtual IP address
            'nameserver1'	=>	$a->getNs1(),
            'nameserver2'	=>	$a->getNs2(),
            'nameserver3'	=>	$a->getNs3(),
            'nameserver4'	=>	$a->getNs4(),
        );
        if ($p->getMaxPop()) {
            $params['allow6'] = 'mail';
        }
        if ($p->getMaxFtp() > 0) {
            $params['allow7'] = 'ftp';
        }
        if ($p->getMaxSql() > 0) {
            $params['allow8'] = 'mysql';
        }

        $response = $this->_makeRequest('modify-reseller', $params);

        if (isset($response['status']) && $response['status'] == 'success') {
            return true;
        } else {
            $placeholders = ['action' => __trans('create reseller account'), 'type' => 'Virtualmin'];
            throw new Server_Exception('Failed to :action: on the :type: server, check the error logs for further details', $placeholders);
        }
    }

    /**
     * Remove all special chars, this will need to be revisted.
     *
     * @param mixed $password
     *
     * @return string
     */
    private function _encodePassword($password): string
    {
        return urlencode($password);
    }
}
