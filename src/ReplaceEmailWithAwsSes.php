<?php

namespace Keithkoslowsky\ReplaceEmailWithAwsSes;

/**
 * @package Replace_Email_With_AWS_Ses
 * @version 1.0.0
 */
/*
Plugin Name: Replace Email With AWS Ses
Description: A drop-in replacement for the way WordPress sends email. No SMTP or setting up the email client on a server. Send with AWS SES instead with this plugin and a few environment variables.
Author: Keith Koslowsky
Version: 1.0.0
Requires PHP: 7.0
Author URI: https://github.com/keithkoslowsky/

Replace Email With AWS Ses is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Replace Email With AWS Ses is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Replace Email With AWS Ses. If not, see https://opensource.org/licenses/GPL-2.0.
*/

if (!defined('ABSPATH')) {
  exit(1);
}

require 'vendor/autoload.php';

use Aws\SesV2\SesV2Client;
use Exception;
use PHPMailer;
use WP_Error;

if (!class_exists('ReplaceEmailWithAwsSes')) {
  class ReplaceEmailWithAwsSes
  {
    /**
     * @var string
     */
    private $pluginName = 'Replace Email With Aws Ses';
    /**
     * @var string
     */
    private $pluginSlug = 'REWAS';
    /**
     * @var false|string
     */
    private $awsProfile;
    /**
     * @var false|string
     */
    private $fromAddress;
    /**
     * @var false|string
     */
    private $fromName;
    /**
     * @var false|string
     */
    private $region;

    public function __construct()
    {
      $this->awsProfile = getenv('AWS_PROFILE');
      $this->fromAddress = getenv('REWAS_FROM_ADDRESS');
      $this->fromName = getenv('REWAS_FROM_NAME');
      $this->region = getenv('REWAS_REGION') ?: 'us-east-1';

      add_filter('pre_wp_mail', function () {
        return true;
      });
      add_filter('wp_mail', array($this, 'wpSendMail'));
      add_action('admin_menu', array($this, 'optionsPageAddToNav'));
    }

    /**
     * Create a MIME message using the built-in PHPMailer from WordPress.
     *
     * @param array $toAddress
     * @param string $subject
     * @param string $message
     * @param array $headers
     * @param array $attachments
     * @return string
     */
    public function getRawMessage(array $toAddress = array(), string $subject = '', string $message = '', array $headers = array(), array $attachments = array()): string
    {
      global $phpmailer;
      if (!($phpmailer instanceof PHPMailer\PHPMailer\PHPMailer)) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      } else {
        $mail = $phpmailer;
      }

      if (is_email($this->fromAddress)) {
        $mail->setFrom($this->fromAddress, $this->fromName);
      }
      foreach ($toAddress as $sendTo) {
        if (is_email($sendTo)) {
          $mail->addAddress($sendTo);
        }
      }
      $mail->Subject = $subject;
      $mail->Body = $message;
      $mail->AltBody = $message;

      if (!empty($headers)) {
        foreach ($headers as $name => $content) {
          if (!in_array($name, array('MIME-Version', 'X-Mailer'), true)) {
            try {
              $mail->addCustomHeader(sprintf('%1$s: %2$s', $name, $content));
            } catch (PHPMailer\PHPMailer\Exception $e) {
              continue;
            }
          }
        }
      }

      if (!empty($attachments)) {
        foreach ($attachments as $attachment) {
          try {
            if (validate_file($attachment) && file_exists($attachment)) {
              $mail->addAttachment($attachment);
            }
          } catch (PHPMailer\PHPMailer\Exception $e) {
            $this->logError(json_encode($e));
            continue;
          }
        }
      }

      $rawMessage = '';
      if (!$mail->preSend()) {
        $this->logError(json_encode($mail->ErrorInfo));
      } else {
        // Create a new variable that contains the MIME message.
        $rawMessage = $mail->getSentMIMEMessage();
      }

      return $rawMessage;
    }

    /**
     * Send any errors to the standard error log.
     *
     * @param string $errorMessage
     * @return void
     */
    private function logError(string $errorMessage)
    {
      error_log($errorMessage);
    }

    /**
     * Create the ability to test and email in the admin panel.
     *
     * @return void
     */
    public function optionsPageHtml()
    {
      // check user capabilities
      if (!current_user_can('manage_options')) {
        return;
      }
      ?>
      <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="<?php menu_page_url($this->pluginSlug) ?>" method="post">
          <?php
          $nonceName = $this->pluginSlug . 'sendTestEmail';
          wp_nonce_field($nonceName, $nonceName);

          submit_button(__('Send test email', 'textdomain'));

          if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            echo __("Email sent.", 'textdomain');
          }
          ?>
        </form>
      </div>
      <?php
    }

    /**
     * Add admin section to the navigation in WordPress.
     *
     * @return void
     */
    public function optionsPageAddToNav()
    {
      $hookName = add_submenu_page(
        'options-general.php',
        $this->pluginName,
        $this->pluginName,
        'manage_options',
        $this->pluginSlug,
        array($this, 'optionsPageHtml')
      );

      add_action('load-' . $hookName, array($this, 'optionsPageSubmit'));
    }

    /**
     * Send a test email if button is clicked in the admin panel.
     *
     * @return void
     */
    public function optionsPageSubmit()
    {
      // check user capabilities
      if (!current_user_can('manage_options')) {
        return;
      }

      // check nonce
      $nonceName = $this->pluginSlug . 'sendTestEmail';
      if (isset($_POST[$nonceName])
        && wp_verify_nonce($_POST[$nonceName], $nonceName)) {
        $this->testSendEmail();
      }
    }

    /**
     * Send an email, based on properly formatted parameters.
     *
     * @param array $to
     * @param string $subject
     * @param string $message
     * @param array $headers
     * @param array $attachments
     * @return bool
     */
    public function sendEmail(array $to, string $subject, string $message, array $headers = [], array $attachments = []): bool
    {
      if (empty($to) || empty($subject) || empty($message)) {
        $this->logError('Missing required arguments to sendEmail.');
        return false;
      }

      if (empty($this->fromAddress)) {
        $this->logError('REWAS_FROM_ADDRESS not set.');
        return false;
      }

      try {
        $rawMessage = $this->getRawMessage($to, $subject, $message, $headers, $attachments);

        $this->sendEmailWithAWS($rawMessage, $to);

        return true;
      } catch (Exception $e) {
        $this->logError($e->getMessage());

        return false;
      }
    }

    /**
     * Send an email using the AWS SDK
     *
     * @param string $rawMessage
     * @param array $to
     * @return void
     */
    public function sendEmailWithAWS(string $rawMessage, array $to = array())
    {
      if (empty($rawMessage) || empty($to)) {
        $this->logError('sendEmailWithAws: a parameter is not set properly.');
        return;
      }

      $options = [
        'version' => '2019-09-27',
        'region' => $this->region,
      ];
      if ($this->awsProfile) $options['profile'] = $this->awsProfile;

      $client = new SesV2Client($options);

      $client->sendEmail([
        'Content' => [
          'Raw' => [
            'Data' => $rawMessage
          ]
        ],
        'Destination' => [
          'ToAddresses' => $to,
        ],
        'FromEmailAddress' => $this->fromAddress
      ]);
    }

    /**
     * Send an email with a preconfigured message using WordPress' wp_mail function.
     *
     * @return void
     */
    public function testSendEmail()
    {
      global $wp_version;
      require_once(ABSPATH . WPINC . '/pluggable.php');
      wp_mail($this->fromAddress, 'Test sending an email', sprintf("<p>WP Version: %s</p></p><p>PHP version: %s</p>", $wp_version, phpversion()), '', '');
    }

    /**
     * Accept input from standard wp_mail and translate the data to plugin's way of sending the mail.
     *
     * @param array $args
     * @return bool
     */
    public function wpSendMail(array $args = array()): bool
    {
      $to = !is_array($args['to']) ? explode(',', $args['to']) : $args['to'];

      if (!is_array($args['attachments'])) {
        $attachments = explode('\n', str_replace('\r\n', '\n', $args['attachments'] ?? ''));
      } else {
        $attachments = $args['attachments'];
      }

      if (!is_array($args['headers'])) {
        $headers = explode('\n', str_replace('\r\n', '\n', $args['headers'] ?? ''));
      } else {
        $headers = $args['headers'];
      }

      $success = $this->sendEmail($to,
        $args['subject'] ?? '',
        $args['message'] ?? '',
        $headers,
        $attachments
      );
      if ($success) {
        do_action('wp_mail_succeeded', $args);
        return true;
      } else {
        $errorMessage = 'Error sending email.';
        $error = new WP_Error();
        $error->add('wp_mail_failed', $errorMessage, $args);
        do_action('wp_mail_failed', $error);

        $this->logError($errorMessage);

        return false;
      }
    }
  }
}

new ReplaceEmailWithAwsSes();
