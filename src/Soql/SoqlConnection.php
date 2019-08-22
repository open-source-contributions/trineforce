<?php

declare(strict_types=1);

namespace Codelicia\Soql;

use Assert\Assertion;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Client;
use function func_get_args;
use function json_decode;
use function sprintf;

class SoqlConnection implements Connection
{
    /** @var string */
    private $salesforceInstance;

    /** @var string */
    private $consumerKey;

    /** @var string */
    private $consumerSecret;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var Client */
    private $conn;

    public function __construct(array $params, string $username, string $password)
    {
        Assertion::keyExists($params, 'salesforceInstance');
        Assertion::keyExists($params, 'consumerKey');
        Assertion::keyExists($params, 'consumerSecret');

        $this->salesforceInstance = $params['salesforceInstance'];
        $this->consumerKey        = $params['consumerKey'];
        $this->consumerSecret     = $params['consumerSecret'];

        $this->username = $username;
        $this->password = $password;

        $token = $this->retrieveAccessToken();

        $this->conn = new Client([
            'base_uri' => $this->salesforceInstance,
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $token),
                'X-PrettyPrint' => '1',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function prepare($prepareString) : SoqlStatement
    {
        return new SoqlStatement($this->conn, $prepareString);
    }

    public function query() : SoqlStatement
    {
        $args = func_get_args();
        $sql  = $args[0];
        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    public function quote($input, $type = ParameterType::STRING) : string
    {
        return "'" . $this->conn->escape_string($input) . "'";
    }

    public function exec($statement) : int
    {
        if ($this->conn->query($statement) === false) {
            throw new SoqlException($this->conn->error, $this->conn->sqlstate, $this->conn->errno);
        }

        return $this->conn->affected_rows;
    }

    public function lastInsertId($name = null) : string
    {
        return $this->conn->insert_id;
    }

    public function beginTransaction() : bool
    {
        $this->conn->query('START TRANSACTION');

        return true;
    }

    public function commit() : bool
    {
        return $this->conn->commit();
    }

    public function rollBack() : bool
    {
        return $this->conn->rollback();
    }

    /** {@inheritdoc} */
    public function errorCode()
    {
        return $this->conn->errno;
    }

    /** {@inheritdoc} */
    public function errorInfo()
    {
        return $this->conn->error;
    }

    private function retrieveAccessToken() : string
    {
        $client  = new Client(['base_uri' => $this->salesforceInstance]);
        $options = [
            'form_params' => [
                'grant_type' => 'password',
                'client_id' => $this->consumerKey,
                'client_secret' => $this->consumerSecret,
                'username' => $this->username,
                'password' => $this->password,
            ],
        ];

        $request      = $client->post('/services/oauth2/token', $options);
        $authResponse = json_decode($request->getBody()->getContents(), true);

        return $authResponse['access_token'];
    }
}
