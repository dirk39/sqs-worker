<?php


use \Aws\Sqs\SqsClient;
use \Symfony\Component\Filesystem\LockHandler;

class Manager implements ManagerInterface
{
  private $client, $lockHandler;

  private $version = '2012-11-05';

  private $visibilityTimeout = 30, $maxNumberOfMessages = 1, $waitTimeSeconds;

  private $timeReceivedMessage;

  public function __construct($appId, $appSecret, $region, array $configs = [])
  {
    $configs = array_replace([
      'credentials' => ['key' => $appId, 'secret' => $appSecret],
      'region' => $region,
      'version' => $this->version
    ], $configs);

    $this->client = new SqsClient($configs);
  }

  public function setMaxNumberOfMessages($number)
  {
    $this->maxNumberOfMessages = $number;

    return $this;
  }

  public function setVisibilityTimeout($seconds)
  {
    $this->visibilityTimeout = $seconds;

    return $this;
  }

  public function setWaitTimeSeconds($seconds)
  {
    $this->waitTimeSeconds = $seconds;

    return $this;
  }

  public function run($queueName, $callback, $keepAlive = false, array $listenerConfigs = [])
  {
    if($keepAlive)
    {
      $this->setPermanentListener($queueName);
    }

    $queueUrl = $this->getQueueUrl($queueName);
    $configs = $this->prepareListenerConfigs($listenerConfigs + ['QueueUrl' => $queueUrl]);

    do
    {
      $response = $this->client->receiveMessage($configs);
      $messages = (array)$response['Messages'];
      if(!$messages)
      {
        continue;
      }

      $this->timeReceivedMessage = microtime(true);
      $messages = $this->prepareMessageCollection($messages);

      try
      {
        foreach ($messages as $key => $message) {
          $this->checkMessageTimedOut();

          call_user_func($callback, $message);

          $this->deleteMessage($queueUrl, $message);

        }
      }
      catch(\Exception $e) {
        $this->releaseAllMessages($queueUrl, $messages);
      }


    }while($keepAlive);
  }


  /**
   * @param string $queueUrl
   * @param array $messages
   * @param integer $timeout
   */
  protected function changeVisibilityTimeout($queueUrl, array $messages, $timeout)
  {
    foreach (array_chunk($messages, 10) as $chunkedMessages) {
      $params = [
        'Entries' => array_map(function(Message $message) use ($timeout){
          return [
            'Id' => uniqid("id"),
            'ReceiptHandle' => $message->getReceipt(),
            'VisibilityTimeout' => $timeout,
          ];
        }, $chunkedMessages),
        'QueueUrl' => $queueUrl
      ];

      $result = $this->client->changeMessageVisibilityBatch($params);


    }
  }

  /**
   * @param string $queueUrl
   * @param array $messages
   */
  protected function releaseAllMessages($queueUrl, array $messages)
  {
    $this->changeVisibilityTimeout($queueUrl, $messages, 0);
  }

  protected function checkMessageTimedOut()
  {
    if(($this->timeReceivedMessage + $this->visibilityTimeout) <= microtime(true)) {
      throw new Exception\VisibilityTimeoutException;
    }
  }

  /**
   * @param array $messages
   * @return array
   */
  protected function prepareMessageCollection(array $messages)
  {
    $messageCollection = [];

    foreach ($messages as $message)
    {
      $messageCollection[] = new Message($message['MessageId'],$message['ReceiptHandle'],$message['MD5OfBody'],
        $message['Body'], $message['Attributes'], $message['MessageAttributes']);
    }

    return $messageCollection;
  }

  protected function deleteMessage($queueUrl, Message $message)
  {
    $this->client->deleteMessage([
      'QueueUrl' => $queueUrl,
      'ReceiptHandle' => $message->getReceipt()
    ]);
  }

  protected function getQueueUrl($queueName)
  {
    if(filter_var($queueName,FILTER_VALIDATE_URL)) {
      return $queueName;
    }

    $result = $this->client->getQueueUrl([
      'QueueName' => $queueName
    ]);

    return $result['QueueUrl'];
  }

  /**
   * @param array $options
   *
   * @return array
   */
  protected function prepareListenerConfigs(array $options = [])
  {
    $defaultOptions = [
      'MaxNumberOfMessages' => $this->maxNumberOfMessages,
      'VisibilityTimeout' => $this->visibilityTimeout,
      'WaitTimeSeconds' => $this->waitTimeSeconds
    ];

    return array_replace($defaultOptions, $options);
  }

  /**
   * @param $queueName
   *
   * @throws Exception\ListenerAlreadyRunningException
   */
  protected function setPermanentListener($queueName)
  {
    $this->lockHandler = new LockHandler($this->getTempFileName($queueName));
    if(!$this->lockHandler->lock()) {
      throw new Exception\ListenerAlreadyRunningException($queueName);
    }

    return true;
  }

  /**
   * @param $queueName
   * @return string
   */
  private function getTempFileName($queueName)
  {
    return sha1(__CLASS__.'_'.$queueName).'.lock';
  }


}