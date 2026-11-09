<?php

namespace Eppitnic\Epp;

use Eppitnic\Service\SessionState;
use RedBeanPHP\R;

/**
 * A simple class handling EPP sessions.
 *
 * LICENSE:
 *
 * Copyright (c) Günther Mair <info@inet-services.it>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Net
 * @package     Eppitnic\Epp\Session
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */

class Session extends AbstractObject
{
  /**
   * Every extension element a poll response can carry, by the registry's own
   * names, so parsePollReq() has a branch for each -- SessionPollCoverageTest
   * fails on one missing here. Transfers are absent, arriving in <resData>.
   */
  public const POLL_MESSAGE_ELEMENTS = array(
    // extdom -- object-scoped notifications
    'chgStatusMsgData',
    'delayedDebitAndRefundMsgData',
    'dlgMsgData',
    'dnsErrorMsgData',
    'dnsWarningMsgData',
    'refundRenewsForBulkTransferMsgData',
    'remappedIdnData',
    'simpleMsgData',
    // extepp -- account-scoped notifications
    'creditMsgData',
    'passwdReminder',
    'wrongNamespaceReminder',
  );

  protected $credit = null;
  protected $messages = null;
  protected $msgID = null;
  protected $msgTitle = null;

  /**
   * Class as a string
   *
   * @return string credit
   */
  public function __toString(): string {
    return sprintf("%.2f", $this->credit);
  }

  /**
   * get a single variable/setting from class
   *
   * @param string $var variable name
   * @return mixed value of variable
   */
  public function get(string $var): mixed {
    return $this->$var;
  }

  /**
   * session start
   *
   * @return bool status
   */
  public function hello(): bool {
    $this->xmlQuery = XmlBuilder::hello();

    // query server (will return false)
    $this->ExecuteQuery("session-hello", "");

    // this is the only query with no result code
    if ((substr((string)($this->result?->code ?? ''), 0, 1) == "2")
        && $this->xmlResult instanceof \SimpleXMLElement
        && isset($this->xmlResult->greeting)) {
      // ExecuteQuery() excludes session-hello from its generic keepalive
      // bookkeeping -- see AbstractObject::SESSION_LIFECYCLE_TYPES -- because
      // hello has no result code for it to key on. nic.it documents hello as
      // the in-session keepalive itself, so a greeting is exactly the signal
      // to refresh on.
      if ($this->keepalive) {
        SessionState::remember($this->client->currentCookies());
      }
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * session login/logout background method
   *
   * @param string $which login/logout
   * @return bool status
   */
  private function loginout(string $which): bool {
    // query server
    if ($this->ExecuteQuery($which, "")) {
      // Credit arrives as an extepp extension on login and logout. is_object()
      // alone is not a presence test: SimpleXML answers a missing child with an
      // empty element, so this indexed a namespace key that was not there
      $extepp = $this->responseExtension('extepp');
      if ($extepp !== null && isset($extepp->creditMsgData->credit)) {
        $this->credit = (float)$extepp->creditMsgData->credit;
      }
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * session login
   *
   * @param string $newPW optional new password
   * @return bool status
   */
  public function login(string $newPW = ""): bool {
    $this->xmlQuery = XmlBuilder::login(
      (string)$this->client->EPPCfg->username,
      (string)$this->client->EPPCfg->password,
      (string)$this->client->EPPCfg->lang,
      $newPW,
      isset($this->client->EPPCfg->dnssec->active) && (int)$this->client->EPPCfg->dnssec->active === 1
    );

    $ok = $this->loginout("session-login");

    // session-login is excluded from ExecuteQuery()'s generic keepalive
    // bookkeeping -- retrying a failed login by logging in again would
    // recurse -- so this is the session's own record of whether it opened
    if ($this->keepalive) {
      $ok ? SessionState::remember($this->client->currentCookies()) : SessionState::forget();
    }

    return $ok;
  }

  /**
   * session keepalive
   *
   * @return bool status
   */
  public function keepalive(): bool {
    return $this->hello();
  }

  /**
   * session logout
   *
   * @return bool status
   */
  public function logout(): bool {
    $this->xmlQuery = XmlBuilder::logout($this->client->set_clTRID());

    return $this->loginout("session-logout");
  }

  /**
   * return current message ID
   * if queue has not yet been looked at, we are going to poll it once
   *
   * @return int message ID on top of message stack
   */
  public function pollID(): int {
    if ($this->msgID === null) $this->poll(FALSE);
    return (int)$this->msgID;
  }

  /**
   * check number of messages in polling queue
   * if queue has not yet been looked at, we are going to poll it once
   *
   * @return int amount of messages in queue
   */
  public function pollMessageCount(): int {
    if ($this->messages === null) $this->poll(FALSE);
    return (int)$this->messages;
  }

  /**
   * poll message queue
   *
   * @param bool $store store message to DB (defaults to TRUE)
   * @param string $type polling type (defaults to "req")
   * @param int|null $msgID message ID (default to empty)
   * @return bool status
   */
  public function poll(bool $store = TRUE, string $type = "req", ?int $msgID = null): bool {
    switch (strtolower($type)) {
      case "req":
        break;
      case "ack":
        if (empty($msgID)) {
          $this->setError("Polling of type 'ack' requires a message ID to be set!");
          return FALSE;
        }
        break;
      default:
        $this->setError("Polling of type '".$type."' not supported, choose one of 'req' or 'ack'.");
        return FALSE;
        break;
    }

    $this->xmlQuery = XmlBuilder::poll($this->client->set_clTRID(), $type, empty($msgID) ? null : (int)$msgID);

    // query server
    $qrs = $this->ExecuteQuery("session-poll", "poll");

    // look at message counter
    if ($this->xmlResult instanceof \SimpleXMLElement && isset($this->xmlResult->response->msgQ)) {
      $this->messages = (int)$this->xmlResult->response->msgQ->attributes()->count;
      $this->msgID = (int)$this->xmlResult->response->msgQ->attributes()->id;
      $this->msgTitle = (string)$this->xmlResult->response->msgQ->msg;

      // parse message (only in case of a poll "req" that succeeded) and store it
      if ((strtolower($type) == "req") && ($store === TRUE) && $qrs) {
        $parsed = $this->parsePollReq();
        R::exec("
          INSERT INTO messages (cl_trid, sv_trid, type, domain, ac_id, re_id, data)
          VALUES (:cl_trid, :sv_trid, :type, :domain, :ac_id, :re_id, :data)
        ", [
          ':cl_trid' => $this->client->get_clTRID(),
          ':sv_trid' => $this->svTRID,
          ':type'    => $parsed['type'],
          ':domain'  => $parsed['domain'],
          ':ac_id'   => $parsed['acID'] ?? null,
          ':re_id'   => $parsed['reID'] ?? null,
          ':data'    => $parsed['data'],
        ]);
      }
    } else if ($qrs === TRUE) {
      $this->messages = 0;
    }

    // see if we want to store an answer
    if (($store === TRUE) && $qrs) {
      R::exec("
        INSERT INTO msgqueue (cl_trid, sv_trid, sv_code, status, sv_httpcode, sv_httpheaders, sv_httpdata)
        VALUES (:cl_trid, :sv_trid, :sv_code, :status, :sv_httpcode, :sv_httpheaders, :sv_httpdata)
      ", [
        ':cl_trid'        => $this->client->get_clTRID(),
        ':sv_trid'        => $this->svTRID,
        ':sv_code'        => $this->svCode,
        ':status'         => 0,
        ':sv_httpcode'    => $this->result?->code,
        ':sv_httpheaders' => $this->result?->headers,
        ':sv_httpdata'    => $this->result?->body,
      ]);
    }

    return $qrs;
  }

  /**
   * method to remove trailing slashes from domain names (dnsErrorMsgData cases)
   *
   * @param string $domain domain name
   * @return string domain name
   */
  protected function stripTrailingDots(string $domain): string {
    return (substr($domain, strlen($domain)-1) == ".") ? substr($domain, 0, strlen($domain)-1) : $domain;
  }

  /**
   * summarise a DnsValidatorResult (extdom-2.0): which validation tests ran
   * and how each came out.
   *
   * @param \SimpleXMLElement $result a dnsErrorMsgData / dnsWarningData element
   * @return string[] "TestName: STATUS" per test
   */
  protected function dnsTestOutcomes(\SimpleXMLElement $result): array {
    $outcomes = array();
    foreach ($result->tests->test as $test) {
      $name = (string)$test->attributes()->name;
      // a skipped test carries @skipped="true" instead of @status
      $status = isset($test->attributes()->status)
        ? (string)$test->attributes()->status
        : (((string)$test->attributes()->skipped === 'true') ? 'SKIPPED' : '');
      $outcomes[] = $name . ": " . $status;
    }
    return $outcomes;
  }

  /**
   * try to parse message received by poll "req"
   *
   * The queue holds extdom-1.0 and 2.0, which reuse element names while
   * changing structure, so each needs its own test -- one alone left 330 real
   * messages as 'unknown', naming no zone at all.
   *
   * @return array [message type], [domain], [human readable data]
   */
  protected function parsePollReq(): array {
    $title = (string)($this->xmlResult->response->msgQ->msg ?? '');

    $extepp = $this->responseExtension('extepp');
    $extdom = $this->responseExtension('extdom');

    // passwdReminder
    if ($extepp !== null && isset($extepp->passwdReminder->exDate)) {
      return array(
        'type'   => 'passwdReminder',
        'domain' => '',
        'data'   => (string)$extepp->passwdReminder->exDate,
      );
    }

    // creditMsgData
    if ($extepp !== null && isset($extepp->creditMsgData->credit)) {
      return array(
        'type'   => 'creditMsgData',
        'domain' => '',
        'data'   => $title . " (" . (string)$extepp->creditMsgData->credit . ")",
      );
    }

    // wrongNamespaceReminder -- the registry warning that we are still sending
    // an outdated extension namespace. Account-scoped, no domain: it is about
    // this client's protocol usage, not about any one object.
    if ($extepp !== null && isset($extepp->wrongNamespaceReminder)) {
      $namespaces = array();
      foreach ($extepp->wrongNamespaceReminder->wrongNamespaceInfo as $info) {
        $namespaces[] = (string)$info->wrongNamespace . " -> " . (string)$info->rightNamespace;
      }
      return array(
        'type'   => 'wrongNamespaceReminder',
        'domain' => '',
        'data'   => $title . (empty($namespaces) ? "" : " (" . implode(", ", $namespaces) . ")"),
      );
    }

    // delayedDebitAndRefundMsgData. Declared in extdom-2.0, not extepp -- this
    // used to be looked for under extepp only, so every one of them fell
    // through to 'unknown' and the domain being debited was discarded.
    foreach (array($extdom, $extepp) as $extension) {
      if ($extension !== null && isset($extension->delayedDebitAndRefundMsgData->amount)) {
        $data = $extension->delayedDebitAndRefundMsgData;
        return array(
          'type'   => 'delayedDebitAndRefundMsgData',
          'domain' => $this->stripTrailingDots((string)$data->name),
          'data'   => $title . " (" . (string)$data->name . " / " . (string)$data->amount . ")",
        );
      }
    }

    // refundRenewsForBulkTransferMsgData -- a bulk operation covering many
    // domains at once, so there is deliberately no single domain to record;
    // the bulkTransferId is what ties it back to the operation.
    if ($extdom !== null && isset($extdom->refundRenewsForBulkTransferMsgData->bulkTransferId)) {
      $refund = $extdom->refundRenewsForBulkTransferMsgData;
      return array(
        'type'   => 'refundRenewsForBulkTransferMsgData',
        'domain' => '',
        'data'   => $title . " (" . (string)$refund->domainsNum . " domains / " .
                    (string)$refund->amount . " / bulk transfer " . (string)$refund->bulkTransferId . ")",
      );
    }

    // remappedIdnData: the registry created a *different* IDN than requested.
    // The created name is the one that exists, so that is what is stored
    if ($extdom !== null && isset($extdom->remappedIdnData->idnCreated)) {
      $remap = $extdom->remappedIdnData;
      return array(
        'type'   => 'remappedIdnData',
        'domain' => $this->stripTrailingDots((string)$remap->idnCreated),
        'data'   => $title . " (requested " . (string)$remap->idnRequested .
                    ", created " . (string)$remap->idnCreated . ")",
      );
    }

    // dnsWarningMsgData (extdom-2.0), tested before chgStatusMsgData: a warning
    // *contains* one, so the other order classifies every warning as a status
    // change and discards the validation results
    if ($extdom !== null && isset($extdom->dnsWarningMsgData->dnsWarningData)) {
      $warning = $extdom->dnsWarningMsgData->dnsWarningData;
      $outcomes = $this->dnsTestOutcomes($warning);
      return array(
        'type'   => 'dnsWarningMsgData',
        'domain' => $this->stripTrailingDots((string)$warning->domain),
        'data'   => $title . " (" . implode(", ", $outcomes) . ")",
      );
    }

    // dnsErrorMsgData, extdom-2.0 shape: <domain> as an element, tests under
    // <tests>
    if ($extdom !== null && isset($extdom->dnsErrorMsgData->domain)) {
      $error = $extdom->dnsErrorMsgData;
      $outcomes = $this->dnsTestOutcomes($error);
      return array(
        'type'   => 'dnsErrorMsgData',
        'domain' => $this->stripTrailingDots((string)$error->domain),
        'data'   => $title . " (" . implode(", ", $outcomes) . ")",
      );
    }

    // dnsErrorMsgData, extdom-1.0 shape: <report><domain name="..."><test .../>
    if ($extdom !== null && isset($extdom->dnsErrorMsgData->report->domain)) {
      $report = $extdom->dnsErrorMsgData->report->domain;
      $outcomes = array();
      foreach ($report->test as $test) {
        $outcomes[] = (string)$test->attributes()->name . ": " . (string)$test->attributes()->status;
      }
      return array(
        'type'   => 'dnsErrorMsgData',
        'domain' => $this->stripTrailingDots((string)$report->attributes()->name),
        'data'   => $title . " (" . implode(", ", $outcomes) . ")",
      );
    }

    // simpleMsgData -- <name> is unbounded in extdom-2.0, but a message row
    // holds one domain, so the first is what we key on
    if ($extdom !== null && isset($extdom->simpleMsgData->name)) {
      return array(
        'type'   => 'simpleMsgData',
        'domain' => $this->stripTrailingDots((string)$extdom->simpleMsgData->name),
        'data'   => $title,
      );
    }

    // chgStatusMsgData
    if ($extdom !== null && isset($extdom->chgStatusMsgData->name)) {
      $change = $extdom->chgStatusMsgData;
      $states = array();
      $ns = $this->xmlResult->getNamespaces(TRUE);
      if (isset($change->targetStatus)) {
        // the target status is expressed with domain:status and rgp:rgpStatus
        // elements, so both namespaces have to be present to read them
        if (isset($ns['domain'])) {
          foreach ($change->targetStatus->children($ns['domain'])->status as $child) {
            $states[] = (string)$child->attributes()->s;
          }
        }
        if (isset($ns['rgp'])) {
          foreach ($change->targetStatus->children($ns['rgp'])->rgpStatus as $child) {
            $states[] = (string)$child->attributes()->s;
          }
        }
      }
      return array(
        'type'   => 'chgStatusMsgData',
        'domain' => $this->stripTrailingDots((string)$change->name),
        'data'   => $title . " (" . implode(", ", $states) . ")",
      );
    }

    // dlgMsgData
    if ($extdom !== null && isset($extdom->dlgMsgData->name)) {
      $delegation = $extdom->dlgMsgData;
      $nameservers = array();
      foreach ($delegation->ns as $child) {
        $nameservers[] = (string)$child;
      }
      return array(
        'type'   => 'dlgMsgData',
        'domain' => $this->stripTrailingDots((string)$delegation->name),
        'data'   => $title . " (" . implode(", ", $nameservers) . ")",
      );
    }

    // domain transfers
    $domainData = $this->responseData('domain');
    if ($domainData !== null && isset($domainData->trnData->name)) {
      $transfer = $domainData->trnData;
      // acID is needed to compare transfer-outs on 'serverApproved'. Both cast
      // to string: poll() binds them straight into the INSERT, and a
      // SimpleXMLElement survives that only via __toString()
      return array(
        'type'   => (string)$transfer->trStatus . "Transfer",
        'domain' => $this->stripTrailingDots((string)$transfer->name),
        'data'   => $title . ": from " . (string)$transfer->acID .
                    " (" . (string)$transfer->acDate . ") to " . (string)$transfer->reID .
                    " (" . (string)$transfer->reDate . ")",
        'acID'   => (string)$transfer->acID,
        'reID'   => (string)$transfer->reID,
      );
    }

    // unknown type
    return array(
      'type'   => 'unknown',
      'domain' => '',
      'data'   => $title,
    );
  }

  /**
   * show credit
   *
   * @return float|null amount or null (if login did not succeed)
   */
  public function showCredit(): ?float {
    return $this->credit;
  }
}
