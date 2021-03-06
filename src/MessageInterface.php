<?php

namespace SQSManager;

interface MessageInterface
{

  /**
   * MessageInterface constructor.
   * @param $messageId
   * @param $receiptHandle
   * @param $md5OfBody
   * @param $body
   * @param array $attributes
   * @param array $messageAttributes
   */
  public function __construct($messageId, $receiptHandle, $md5OfBody, $body, $attributes = [], $messageAttributes = []);

  /**
   * @return string
   */
  public function getReceipt();

  /**
   * @return string
   */
  public function getBody();

  /**
   * @return array
   */
  public function getAttributes();

  /**
   * @return array
   */
  public function getMessageAttributes();

}