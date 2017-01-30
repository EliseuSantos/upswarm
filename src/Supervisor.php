<?php

namespace Upswarm;

use Evenement\EventEmitterInterface;
use React\ChildProcess\Process;
use React\EventLoop\Factory;
use React\Socket\Server;
use React\Stream\Stream;
use Upswarm\Instruction\Identify;
use Upswarm\Instruction\KillService;
use Upswarm\Instruction\SpawnService;
use Upswarm\Message;

/**
 * Upswarm supervisor orchestrate services and handle message exchanging
 * between then.
 */
class Supervisor
{
    /**
     * How to name an unknow service in $connections array.
     */
    const UNKNOW_SERVICE = 'unknow';

    /**
     * Socket that will be used to receive connections from Services and enxange
     * messages with then.
     * @var \React\Socket\Server
     */
    protected $remoteStream;

    /**
     * Port that the supervisor will listen to
     * @var int
     */
    protected $port;

    /**
     * ReactPHP loop.
     * @var \React\EventLoop\LoopInterface;
     */
    protected $loop;

    /**
     * Services that are running.
     *
     * @example [
     *              'ServiceAName' => [
     *                  <Process>,
     *                  <Process>
     *              ],
     *              'ServiceBName' => [
     *                  <Process>,
     *                  <Process>
     *              ]
     *          ];
     *
     * @var array
     */
    protected $processes = [];

    /**
     * The target service topology
     *
     * @example [
     *              'ServiceAName' => 3,
     *              'ServiceBName' => 1
     *          ];
     *
     * @var array
     */
    protected $topology = [];

    /**
     * Connections that are open within $remoteStream. Whenever a new connection
     * is openned that connection is placed as an 'unknow' service. After that
     * same service sends a Message with the data type 'Identify' it is placed
     * in the correct array.
     *
     * @example [
     *              'unknow' => [ // New connections
     *                  '<id>' => <connection>,
     *                  '<id>' => <connection>
     *              ],
     *              'ServiceAName' => [ // Connections of services identified as "ServiceAName"
     *                  '<id>' => <connection>,
     *                  '<id>' => <connection>
     *              ],
     *              'ServiceBName' => [
     *                  '<id>' => <connection>,
     *              ]
     *          ];
     *
     * @var array
     */
    protected $connections = [];

    /**
     * Initializes the Supervisor
     *
     * @param integer $port Port to listen to.
     */
    public function __construct(int $port = 8300)
    {
        $this->loop         = Factory::create();
        $this->remoteStream = new Server($this->loop);
        $this->port         = $port;

        $this->prepareMessageHandling($this->remoteStream);
        $this->prepareTopology();
    }

    /**
     * Listen to a TopologyReader update events in order to be able to tell
     * how is the topology in real time.
     *
     * @return void
     */
    protected function prepareTopology()
    {
        $this->topologyReader = new TopologyReader($this->loop);

        $this->topologyReader->on('info', function ($message) {
            echo "$message\n";
        });

        $this->topologyReader->on('error', function ($message) {
            echo "<error>$message</error>\n";
        });

        $this->topologyReader->on('update', function ($topology) {
            $this->topology = $topology;
            $this->updateTopology();
        });
    }

    /**
     * Updates the topology (the amount of services of each type) running based
     * in the $topology property of the Supervisor.
     *
     * @return void
     */
    protected function updateTopology()
    {
        foreach ($this->topology as $serviceName => $amount) {
            $diff = $amount - count($this->processes[$serviceName] ?? []);

            if ($diff > 0) {
                for ($i=0; $i < $diff; $i++) {
                    $this->loop->addTimer($i, function () use ($serviceName) {
                        $this->spawn($serviceName);
                    });
                }
            } elseif ($diff < 0) {
                for ($i=0; $i < $diff*-1; $i++) {
                    $this->loop->addTimer($i, function () use ($serviceName) {
                        $this->stop($serviceName);
                    });
                }
            }
        }
    }

    /**
     * Register the basic events on how incoming messages will be handled by
     * the Supervisor.
     * @param  EventEmitterInterface $stream Socket that will be used to receive connections from Services and enxange messages with then.
     * @return void
     */
    protected function prepareMessageHandling(EventEmitterInterface $stream)
    {
        // Whenever a new connection is received
        $stream->on('connection', function ($conn) {
            // Place it as an unknow connections
            $this->connections[static::UNKNOW_SERVICE][] = $conn;

            // If data is received from it, dispatch or evaluate message
            $conn->on('data', function ($data) use ($conn) {
                $message = @unserialize($data);
                if ($message instanceof Message) {
                    $this->loop->nextTick(function () use ($message, $conn) {
                        $this->dispatchMessage($message, $conn);
                    });
                }
            });

            // If connection is terminated, remove it from connections
            $conn->on('end', function () use ($conn) {
                if (isset($this->connections[static::UNKNOW_SERVICE])) {
                    $key = array_search($conn, $this->connections[static::UNKNOW_SERVICE]);
                    if ($key) {
                        unset($this->connections[static::UNKNOW_SERVICE][$key]);
                    }
                }
            });
        });
    }

    /**
     * Dispatchs incoming message to an Service or to be evaluated by the
     * Supervisor.
     *
     * @param  Message $message Incoming message.
     * @param  Stream  $conn    Connection from where the message came from.
     *
     * @return void
     */
    protected function dispatchMessage(Message $message, Stream $conn)
    {
        // If message have an receipt. Redirect message to it.
        if ($message->receipt) {
            $this->deliverMessage($message, $message->receipt);

            return;
        }

        $this->evaluateMessageToSupervisor($message, $conn);
    }

    /**
     * Evaluates a message that was directed to the Supervisor.
     *
     * @param  Message $message Incoming message.
     * @param  Stream  $conn    Connection from where the message came from.
     *
     * @return void
     */
    protected function evaluateMessageToSupervisor(Message $message, Stream $conn)
    {
        switch ($message->getDataType()) {
            case SpawnService::class:
                $this->spawn($message->getData()->service);
                break;

            case KillService::class:
                $this->kill($message);
                break;

            case Identify::class:
                $this->identify($message->getData(), $conn);
                break;

            default:
                echo "Unknow instruction in Message to supervisor: ".$message->getDataType();
                break;
        }
    }

    /**
     * Delives the given Message to the receipt Service
     *
     * @param  Message $message Incoming message.
     * @param  string  $receipt String identifying the receipt. It may be the name or an id of a Service.
     *
     * @return void
     */
    protected function deliverMessage(Message $message, string $receipt)
    {
        // If the receipt is not an Id (it's a name then)
        if (! ctype_xdigit($receipt)) {
            // Deliver the message to any Service instance of that name.
            if (isset($this->connections[$receipt])) {
                $random_key = array_rand($this->connections[$receipt]);
                if ($random_key) {
                    $this->connections[$receipt][$random_key]->write(serialize($message));
                }
            }
            return;
        }

        // If the receipt is an Id
        foreach ($this->connections as $service) {
            // Iterate throught the connections and deliver the message.
            foreach ($service as $id => $conn) {
                if ($id == $receipt) {
                    $conn->write(serialize($message));
                    return;
                }
            }
        }
    }

    /**
     * Spawn a new instance of $serviceName
     *
     * @param  string $serviceName Name of the service to be spawned.
     *
     * @return void
     */
    public function spawn(string $serviceName)
    {
        echo "Spawnning $serviceName\n";

        // Prepares to create new process
        $process = new Process("exec ./upswarm spawn ".str_replace('\\', '\\\\', $serviceName));

        $this->loop->nextTick(function () use ($process, $serviceName) {
            // Starts process and pipe outputs to supervisor
            $process->start($this->loop);
            $this->processes[$serviceName][] = $process;

            $echoChildOutput = function ($output) use ($serviceName) {
                echo "[{$serviceName}]: {$output}";
            };

            $process->stdout->on('data', $echoChildOutput);
            $process->stderr->on('data', $echoChildOutput);
        });

        // Register exit event of process
        $process->on('exit', function ($exitCode, $termSignal) use ($process, $serviceName) {
            $key = array_search($process, $this->processes[$serviceName]);
            echo "[$serviceName] exit $exitCode $termSignal\n";
            unset($this->processes[$serviceName][$key]);
        });
    }

    /**
     * Kills a service or an instance
     *
     * @param  Message $killingMessage Message containing a KillService instruction.
     *
     * @return void
     */
    public function kill(Message $killingMessage)
    {
        if ($killingMessage->getDataType() !== KillService::class) {
            echo "Invalid KillService instruction received.";
            return;
        }

        $instruction = $killingMessage->getData();
        $serviceName = $instruction->service;

        echo "Killing {$instruction->service}\n";

        // Kills processes
        if (isset($this->processes[$serviceName])) {
            foreach ($this->processes[$serviceName] as $process) {
                $process->terminate();
            }
        }

        // Send response
        $response = new Message("'$serviceName' killed successfully.");
        $killingMessage->respond($response);
        $this->deliverMessage($response, $response->receipt);
    }

    /**
     * Stops an instance of $serviceName
     *
     * @param  string $serviceName Name of the service to be stopped.
     *
     * @return void
     */
    public function stop(string $serviceName)
    {
        echo "Stopping $serviceName\n";

        if (isset($this->processes[$serviceName]) && count($this->processes[$serviceName]) > 0) {
            $key = array_rand($this->processes[$serviceName]);
            $this->processes[$serviceName][$key]->terminate();
        }
    }

    /**
     * Parse Itentify instruction that came from a connection.
     *
     * @param  Identify $instruction Intetification instruction.
     * @param  Stream   $conn        Connection to be identified.
     *
     * @return void
     */
    public function identify(Identify $instruction, Stream $conn)
    {
        if (! (is_string($instruction->serviceName) && is_string($instruction->serviceId))) {
            return;
        }

        // Register connection in the correct name and with it's id
        $this->connections[$instruction->serviceName][$instruction->serviceId] = $conn;

        // Removes connection from the unknow connections
        if (isset($this->connections[static::UNKNOW_SERVICE])) {
            $key = array_search($conn, $this->connections[static::UNKNOW_SERVICE]);
            unset($this->connections[static::UNKNOW_SERVICE]);
        }

        // Registers callback to remove connection if it ends.
        $conn->on('end', function () use ($instruction) {
            if (isset($this->connections[$instruction->serviceName][$instruction->serviceId])) {
                unset($this->connections[$instruction->serviceName][$instruction->serviceId]);
            }
        });
    }

    /**
     * Runs supervisor process.
     *
     * @return void
     */
    public function run()
    {
        $this->remoteStream->listen($this->port);

        $this->loop->addTimer(5, function () {
            $this->loop->addPeriodicTimer(2, function () {
                $this->updateTopology();
            });
        });

        $this->loop->run();
    }
}
