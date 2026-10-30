<?php

use Eppitnic\Api\Access;
use Eppitnic\Api\Auth;
use Eppitnic\Api\Json;
use Eppitnic\Epp\Client;
use Eppitnic\Epp\Contact;
use Eppitnic\Persistence\Scope;
use Eppitnic\Service\EppSession;
use Eppitnic\Support\Validate;
use Eppitnic\Support\Warnings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RedBeanPHP\R;

/**
 * serialize a Contact's relevant fields for a JSON response
 */
function contactToArray(Contact $contact): array {
    return [
        'handle'                => $contact->get('handle'),
        'status'                => $contact->get('status'),
        'linked'                => $contact->isLinked(),
        'name'                  => $contact->get('name'),
        'org'                   => $contact->get('org'),
        'street'                => $contact->get('street'),
        'street2'               => $contact->get('street2'),
        'street3'               => $contact->get('street3'),
        'city'                  => $contact->get('city'),
        'province'              => $contact->get('province'),
        'postalcode'            => $contact->get('postalcode'),
        'countrycode'           => $contact->get('countrycode'),
        'voice'                 => $contact->get('voice'),
        'fax'                   => $contact->get('fax'),
        'email'                 => $contact->get('email'),
        'authinfo'              => $contact->get('authinfo'),
        'consentforpublishing'  => $contact->get('consentforpublishing'),
        'nationalitycode'       => $contact->get('nationalitycode'),
        'entitytype'            => $contact->get('entitytype'),
        'regcode'               => $contact->get('regcode'),
        'schoolcode'            => $contact->get('schoolcode'),
    ];
}

$app->get('/v1/contacts', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope] = Auth::actor($request);
    $params  = $request->getQueryParams();

    $nic = new Client();
    $contact = new Contact($nic);
    $contacts = $contact->listContacts($scope, ($params['active'] ?? '1') !== '0');

    return Json::response($response, ['contacts' => $contacts]);
});

$app->get('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $handle = $args['handle'];

    if ( ! Access::canAccessContact($handle, $scope)) {
        return Json::response($response, ['error' => 'You are not authorized to view this contact'], 403);
    }

    // the registry is authoritative: its answer is returned as-is, never
    // overlaid with the local row -- loadDB() re-initializes before its lookup
    // and blanks the object when that misses. Mirrors GET /v1/domains/{name}
    try {
        $contact = EppSession::run(function ($nic) use ($handle) {
            $contact = new Contact($nic);
            return $contact->fetch($handle) ? $contact : null;
        }, $debug);
    } catch (\RuntimeException $e) {
        // registry unreachable -- indistinguishable from "not found" as far as
        // this route is concerned, both fall through to the local fallback
        $contact = null;
    }

    if ($contact !== null) {
        return Json::response($response, ['contact' => contactToArray($contact), 'stale' => false]);
    }

    // registry lookup failed: serve the local state, flagged stale. The ACL
    // argument stays TRUE, Access::canAccessContact() having allowed this already --
    // scoping by reseller would 404 contacts hanging off a domain it owns
    $nic = new Client();
    $contact = new Contact($nic);
    if ( ! $contact->loadDB($handle, Scope::operator($scope->userId))) {
        return Json::response($response, ['error' => "Contact '{$handle}' not found"], 404);
    }

    return Json::response($response, ['contact' => contactToArray($contact), 'stale' => true]);
});

$app->post('/v1/contacts', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $params = $request->getParsedBody() ?? [];

    // an admin may create a contact for any reseller; everyone else's go to
    // their own, and saying otherwise is refused rather than ignored
    $resellerId = $scope->resellerId;
    if (array_key_exists('reseller_id', $params)) {
        if ( ! $scope->isAdmin()) {
            return Json::response($response, ['error' => 'Only an admin may choose the reseller'], 403);
        }
        $resellerId = (int) $params['reseller_id'];
        if ((int) R::getCell('SELECT COUNT(*) FROM resellers WHERE id = ?', [$resellerId]) === 0) {
            return Json::response($response, ['error' => "Reseller {$resellerId} not found"], 404);
        }
    }

    if ($err = Validate::requireFields($params, ['name']) ?? Validate::maxLength($params, Validate::CONTACT_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }
    if ( ! empty($params['email']) && ! Validate::isEmail($params['email'])) {
        return Json::response($response, ['error' => 'email is not a valid address'], 400);
    }

    if (Contact::equalizeNameOrg((string) ($params['name'] ?? ''), (string) ($params['org'] ?? '')) === null) {
        return Json::response($response, ['error' => Contact::NAME_ORG_RULE], 400);
    }

    try {
        $result = EppSession::run(function ($nic) use ($params, $resellerId, $scope) {
            $contact = new Contact($nic);
            foreach ($params as $key => $value) {
                if ($key === 'handle' || $key === 'fax') {
                    continue; // handle is set below; fax is accepted and ignored
                }
                if (in_array($key, Contact::FIELDS, true)) {
                    $contact->set($key, $value);
                }
            }
            $contact->set('handle', empty($params['handle']) ? $contact->generateHandle() : $params['handle']);
            if (empty($params['authinfo'])) {
                $contact->set('authinfo', $contact->authinfo());
            }
            if ( ! $contact->create()) {
                return ['ok' => false, 'error' => $contact->getError()];
            }
            $warnings = $contact->storeDB($resellerId, $scope->userId)
                ? []
                : [Warnings::localWrite("contact '{$contact->get('handle')}'", $contact->getError())];
            return ['ok' => true, 'contact' => $contact, 'warnings' => $warnings];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['contact' => contactToArray($result['contact'])] + Warnings::field($result['warnings']), 201);
});

$app->patch('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $handle = $args['handle'];
    $params = $request->getParsedBody() ?? [];

    if ( ! Access::ownsContact($handle, $scope)) {
        return Json::response($response, ['error' => 'You are not authorized to update this contact'], 403);
    }
    if ($err = Validate::maxLength($params, Validate::CONTACT_FIELD_MAX_LENGTHS)) {
        return Json::response($response, ['error' => $err], 400);
    }
    if ( ! empty($params['email']) && ! Validate::isEmail($params['email'])) {
        return Json::response($response, ['error' => 'email is not a valid address'], 400);
    }

    try {
        $result = EppSession::run(function ($nic) use ($handle, $params, $scope) {
            $contact = new Contact($nic);
            if ( ! $contact->fetch($handle)) {
                return ['ok' => false, 'status' => 404, 'error' => "Contact '{$handle}' not found"];
            }
            foreach ($params as $key => $value) {
                if ($key !== 'fax' && in_array($key, Contact::FIELDS, true)) {
                    $contact->set($key, $value);
                }
            }
            if ( ! $contact->update()) {
                return ['ok' => false, 'status' => 400, 'error' => $contact->getError()];
            }
            $warnings = $contact->updateDB($handle, $scope)
                ? []
                : [Warnings::localWrite("contact '{$handle}'", $contact->getError())];
            return ['ok' => true, 'contact' => $contact, 'warnings' => $warnings];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], $result['status']);
    }

    return Json::response($response, ['contact' => contactToArray($result['contact'])] + Warnings::field($result['warnings']));
});

$app->delete('/v1/contacts/{handle}', function (Request $request, Response $response, array $args): Response {
    ['scope' => $scope, 'debug' => $debug] = Auth::actor($request);
    $handle = $args['handle'];

    // checked before the registry: deleting there is not undone by the
    // local scoping that follows
    if ( ! Access::ownsContact($handle, $scope)) {
        return Json::response($response, ['error' => 'You are not authorized to delete this contact'], 403);
    }
    if (($held = Contact::referencedBy($handle)) !== []) {
        return Json::response($response, ['error' => Contact::stillRegistrantMessage($handle, $held)], 409);
    }

    try {
        $result = EppSession::run(function ($nic) use ($handle, $scope) {
            $contact = new Contact($nic);
            if ( ! $contact->delete($handle)) {
                return ['ok' => false, 'error' => $contact->getError()];
            }
            $warnings = $contact->deleteContactDB($handle, $scope)
                ? []
                : [Warnings::localWrite("contact '{$handle}'", $contact->getError())];
            return ['ok' => true, 'warnings' => $warnings];
        }, $debug);
    } catch (\RuntimeException $e) {
        return Json::response($response, ['error' => $e->getMessage()], 502);
    }

    if ( ! $result['ok']) {
        return Json::response($response, ['error' => $result['error']], 400);
    }

    return Json::response($response, ['deleted' => true, 'handle' => $handle] + Warnings::field($result['warnings']));
});
