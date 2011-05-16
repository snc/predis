<?php

namespace Predis\Network;

use Predis\ClientException;
use Predis\ServerException;
use Predis\RedisClusterException;
use Predis\Commands\ICommand;
use Predis\Distribution\RedisClusterDistributor;

class RedisCluster implements IConnectionCluster, \IteratorAggregate {
    private $_pool;
    private $_slots;
    private $_distributor;

    public function __construct() {
        $this->_pool = array();
        $this->_slots = array();
        $this->_distributor = new RedisClusterDistributor();
    }

    public function isConnected() {
        foreach ($this->_pool as $connection) {
            if ($connection->isConnected()) {
                return true;
            }
        }
        return false;
    }

    public function connect() {
        foreach ($this->_pool as $connection) {
            $connection->connect();
        }
    }

    public function disconnect() {
        foreach ($this->_pool as $connection) {
            $connection->disconnect();
        }
    }

    public function add(IConnectionSingle $connection) {
        $parameters = $connection->getParameters();
        $this->_pool["{$parameters->host}:{$parameters->port}"] = $connection;
    }

    public function getConnection(ICommand $command) {
        $cmdHash = $command->getHash($this->_distributor);
        if (!isset($cmdHash)) {
            throw new ClientException(
                sprintf("Cannot send '%s' commands to a Redis cluster", $command->getId())
            );
        }
        $slot = $cmdHash & 0x0FFF;
        if (isset($this->_slots[$slot])) {
            return $this->_slots[$slot];
        }
        $connection = $this->_pool[array_rand($this->_pool)];
        $this->_slots[$slot] = $connection;
        return $connection;
    }

    public function getConnectionById($id) {
        if (isset($this->_pool[$id])) {
            return $this->_pool[$id];
        }
    }

    public function getIterator() {
        return new \ArrayIterator($this->_pool);
    }

    protected function handleMoved(ICommand $command, $moveMessage) {
        list($type, $slot, $id) = explode(' ', $moveMessage, 3);
        $connection = $this->getConnectionById($id);
        if (isset($connection)) {
            if ($type === 'MOVED') {
                $this->_slots[$slot] = $connection;
            }
            return $this->executeCommand($command);
        }
        throw new ClientException("Connection $id is not registered");
    }

    public function writeCommand(ICommand $command) {
        $this->getConnection($command)->writeCommand($command);
    }

    public function readResponse(ICommand $command) {
        return $this->getConnection($command)->readResponse($command);
    }

    public function executeCommand(ICommand $command) {
        $connection = $this->getConnection($command);
        try {
            $reply = $connection->executeCommand($command);
        }
        catch (RedisClusterException $exception) {
            $reply = $exception;
        }
        if ($reply instanceof RedisClusterException) {
            return $this->handleMoved($command, $reply->getMessage());
        }
        return $reply;
    }
}
