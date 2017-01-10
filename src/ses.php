<?php
/*
 * Amazon SimpleEmailService class for PHP
 * Author: Okamos
 *
 */
class SimpleEmailService {
  const SERVICE = 'email';
  const DOMAIN = 'amazonaws.com';
  const ALGORITHM = 'AWS4-HMAC-SHA256';
  const ERROR = "Please check your aws_access_key_id and aws_secret_access_key.\nAnd set correct permissions to your user or group.";

  private $aws_key;
  private $aws_secret;
  private $region;

  private $host;
  private $endpoint;

  private $amz_date;
  private $date;

  private $action;
  private $method;

  private $headers;
  private $query_parameters;

  // aws_access_key_id and aws_secret_access_key is reuqired.
  // Defaults verification of SSL certificate used.
  public function __construct($credentials = array(), $ssl_verify_peer = true) {
    $this -> aws_key = $credentials['aws_access_key_id'];
    $this -> aws_secret = $credentials['aws_secret_access_key'];
    // default is us-east-1
    $this -> region = $credentials['region'] ? $credentials['region'] : 'us-east-1';

    $this -> host = self::SERVICE . '.' . $this -> region . '.' . self::DOMAIN;
    $this -> endpoint = 'https://' . self::SERVICE . '.' . $this -> region . '.' . self::DOMAIN;

    $this -> amz_date = gmdate('Ymd\THis\Z');
    $this -> date = gmdate('Ymd');

    $this -> ssl_verify = $ssl_verify_peer;
  }

  // List all identities your AWS account.
  public function list_identities($identity_type = '') {
    $this -> action = 'ListIdentities';
    $this -> method = 'GET';

    if (!preg_match('/^(EmailAddress|Domain|)$/', $identity_type)) {
      throw new Exception('IdentityType must be EmailAddress or Domain');
      return;
    }

    $parameters = array();
    if ($identity_type) {
      $parameters['IdentityType'] = $identity_type;
    }

    $this -> generate_signature($parameters);
    $context = $this -> create_stream_context();
    if ($res = @file_get_contents($this -> endpoint . '?' . $this -> query_parameters, false, $context)) {
      $identities = new SimpleXMLElement($res);
      return $identities -> ListIdentitiesResult -> Identities -> member;
    } else {
      throw new Exception(self::ERROR);
      return;
    }
  }

  // Send an confirmation email to email address for verification.
  public function verify_email_identity($email) {
    $this -> action = 'VerifyEmailIdentity';
    $this -> method = 'GET';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new Exception('Invalid email');
      return;
    }

    $parameters = array(
      'EmailAddress' => $email
    );

    $this -> generate_signature($parameters);
    $context = $this -> create_stream_context();
    if ($res = @file_get_contents($this -> endpoint . '?' . $this -> query_parameters, false, $context)) {
      $xml = simplexml_load_string($res);
      return $xml -> ResponseMetadata -> RequestId;
    } else {
      throw new Exception(self::ERROR);
      return;
    }
  }

  // Delete an identity from your AWS account.
  public function delete_identity($identity) {
    $this -> action = 'DeleteIdentity';
    $this -> method = 'GET';

    if (!(filter_var($identity, FILTER_VALIDATE_EMAIL) || preg_match('/^([a-z\d]+(-[a-z\d]+)*\.)+[a-z]{2,}$/', $identity))) {
      throw new Exception('Identity must be EmailAddress or Domain');
      return;
    }

    $parameters = array(
      'Identity' => $identity
    );

    $this -> generate_signature($parameters);
    $context = $this -> create_stream_context();
    if ($res = @file_get_contents($this -> endpoint . '?' . $this -> query_parameters, false, $context)) {
      $xml = simplexml_load_string($res);
      return $xml -> ResponseMetadata -> RequestId;
    } else {
      throw new Exception(self::ERROR);
    }
  }

  // TODO: send raw message
  // Send the email to some specified addresses.
  public function send_email($assets = array()) {
    $this -> action = 'SendEmail';
    $this -> method = 'POST';

    $parameters = array(
      'Message.Body.Text.Data' => $assets['body'],
      'Message.Subject.Data' => $assets['subject'],
      'Source' => $assets['from']
    );

    if (isset($asset['to'])) {
      $this -> addAddresses($assets['to']);
    }
    if (isset($asset['cc'])) {
      $this -> addAddresses($assets['cc'], 'cc');
    }
    if (isset($asset['bcc'])) {
      $this -> addAddresses($assets['bcc'], 'bcc');
    }

    $this -> generate_signature($parameters);
    $context = $this -> create_stream_context();
    if ($res = @file_get_contents($this -> endpoint . '?' . $this -> query_parameters, false, $context)) {
      $xml = simplexml_load_string($res);
      return $xml -> SendEmailResult -> MessageId;
    } else {
      throw new Exception(self::ERROR);
    }
  }

  public function addAddresses($addresses, $destination = 'to') {
    $address_index = 1;
    if (is_string($addresses)) {
      $parameters['Destination.' . $destination . 'Addresses.member.' . $address_index] = $addresses;
    }
    if (is_array($addresses)) {
      foreach ($addresses as $address) {
        $parameters['Destination.' . $destination . 'Addresses.member.' . $address_index] = $address;
        $address_index++;
      }
    }
  }

  // Get your AWS account's sending limits.
  public function get_send_quota() {
    $this -> action = 'GetSendQuota';
    $this -> method = 'GET';


    $this -> generate_signature();
    $context = $this -> create_stream_context();
    if ($res = @file_get_contents($this -> endpoint . '?' . $this -> query_parameters, false, $context)) {
      $xml = simplexml_load_string($res);
      return $xml -> GetSendQuotaResult;
    } else {
      throw new Exception(self::ERROR);
    }
  }

  // Get SES sending statistics.
  public function get_send_statistics() {
    $this -> action = 'GetSendStatistics';
    $this -> method = 'GET';


    $this -> generate_signature();
    $context = $this -> create_stream_context();
    if ($res = @file_get_contents($this -> endpoint . '?' . $this -> query_parameters, false, $context)) {
      $xml = simplexml_load_string($res);
      return $xml -> GetSendStatisticsResult -> SendDataPoints -> member;
    } else {
      throw new Exception(self::ERROR);
    }
  }

  private function create_stream_context() {
    $opts = array(
      'ssl' => array(
        'verify_peer' => $this -> ssl_verify,
        'verify_peer_name' => $this -> ssl_verify
      ),
      'http' => array(
        'method' => $this -> method,
        'header' => join("\n", $this -> headers) . "\n"
      )
    );

    return stream_context_create($opts);
  }

  // return binary hmac sha256
  private function generate_signature_key() {
    $date_h = hash_hmac('sha256', $this -> date, 'AWS4' . $this -> aws_secret, true);
    $region_h = hash_hmac('sha256', $this -> region, $date_h, true);
    $service_h = hash_hmac('sha256', self::SERVICE, $region_h, true);
    $signing_h = hash_hmac('sha256', 'aws4_request', $service_h, true);

    return $signing_h;
  }

  // Signing AWS Requests with Signature Version 4
	// see http://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html
  private function generate_signature($parameters = array()) {
    $canonical_uri = '/';

    $parameters['Action'] = $this -> action;
    ksort($parameters);

    $request_parameters = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

    $canonical_headers = 'host:' . $this -> host . "\n" . 'x-amz-date:' . $this -> amz_date . "\n";
    $signed_headers = 'host;x-amz-date';
    $payload_hash = hash('sha256', '');

    # task1
    $canonical_request = $this -> method . "\n" . $canonical_uri . "\n" . $request_parameters . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;

    # task2
    $credential_scope = $this -> date . '/' . $this -> region . '/' . self::SERVICE . '/aws4_request';
    $string_to_sign =  self::ALGORITHM . "\n" . $this -> amz_date . "\n" . $credential_scope . "\n" . hash('sha256', $canonical_request);

    # task3
    $signing_key = $this -> generate_signature_key();
    $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
    $this -> headers[] = 'Authorization:' . self::ALGORITHM . ' Credential=' . $this -> aws_key . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
    $this -> headers[] = 'x-amz-date:' . $this -> amz_date;
    $this -> query_parameters = $request_parameters;
  }
}
?>
