<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter\Daktela;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Exception\AdapterException;
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\RequestFactory;
use Psr\Log\LoggerInterface;

final class DaktelaAdapter implements ContactCentreAdapterInterface
{
    private const ACTIVITIES_MODEL = 'Activities';

    private readonly Client $client;

    public function __construct(
        string $instanceUrl,
        string $accessToken,
        private readonly string $database,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client($instanceUrl, $accessToken);
    }

    public function findContact(string $id): ?Contact
    {
        return $this->findEntity('Contacts', $id, fn (array $data) => Contact::fromArray($data));
    }

    /** @param array<string, mixed> $criteria */
    public function findContactBy(array $criteria): ?Contact
    {
        return $this->findEntityBy('Contacts', $criteria, fn (array $data) => Contact::fromArray($data));
    }

    public function createContact(Contact $contact): Contact
    {
        $data = $this->createEntity('Contacts', $contact->toArray());

        return Contact::fromArray($data);
    }

    public function updateContact(string $id, Contact $contact): Contact
    {
        $data = $this->updateEntity('Contacts', $id, $contact->toArray());

        return Contact::fromArray($data);
    }

    public function upsertContact(string $lookupField, Contact $contact): Contact
    {
        $lookupValue = $contact->get($lookupField);
        if ($lookupValue === null) {
            throw AdapterException::missingId('contact');
        }

        $existing = $this->findContactBy([$lookupField => (string) $lookupValue]);

        if ($existing !== null && $existing->getId() !== null) {
            if (!$this->hasChanges($existing->getData(), $contact->getData())) {
                $this->logger->debug('Skip contact update: no changes', ['id' => $existing->getId()]);
                $existing->set('_syncSkipped', true);

                return $existing;
            }

            return $this->updateContact($existing->getId(), $contact);
        }

        return $this->createContact($contact);
    }

    public function findAccount(string $id): ?Account
    {
        return $this->findEntity('Accounts', $id, fn (array $data) => Account::fromArray($data));
    }

    /** @param array<string, mixed> $criteria */
    public function findAccountBy(array $criteria): ?Account
    {
        return $this->findEntityBy('Accounts', $criteria, fn (array $data) => Account::fromArray($data));
    }

    public function createAccount(Account $account): Account
    {
        $data = $this->createEntity('Accounts', $account->toArray());

        return Account::fromArray($data);
    }

    public function updateAccount(string $id, Account $account): Account
    {
        $data = $this->updateEntity('Accounts', $id, $account->toArray());

        return Account::fromArray($data);
    }

    public function upsertAccount(string $lookupField, Account $account): Account
    {
        $lookupValue = $account->get($lookupField);
        if ($lookupValue === null) {
            throw AdapterException::missingId('account');
        }

        $existing = $this->findAccountBy([$lookupField => (string) $lookupValue]);

        if ($existing !== null && $existing->getId() !== null) {
            if (!$this->hasChanges($existing->getData(), $account->getData())) {
                $this->logger->debug('Skip account update: no changes', ['id' => $existing->getId()]);
                $existing->set('_syncSkipped', true);

                return $existing;
            }

            return $this->updateAccount($existing->getId(), $account);
        }

        return $this->createAccount($account);
    }

    public function findActivity(string $id, ActivityType $type): ?Activity
    {
        /** @var Activity|null */
        return $this->findEntity(self::ACTIVITIES_MODEL, $id, function (array $data) use ($type): Activity {
            $activity = Activity::fromArray($data);
            $activity->setActivityType($type);

            return $activity;
        });
    }

    /** @return \Generator<int, Activity> */
    public function iterateActivities(ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        $request = RequestFactory::buildReadRequest(self::ACTIVITIES_MODEL);
        $request->addFilter('type', 'eq', strtoupper($type->value));

        if ($since !== null) {
            $request->addFilter('time', 'gte', $since->format('Y-m-d H:i:s'));
        }

        $pageSize = 100;
        $currentOffset = $offset;

        while (true) {
            $pageRequest = clone $request;
            $pageRequest->setSkip($currentOffset);
            $pageRequest->setTake($pageSize);

            $response = $this->client->execute($pageRequest);

            if ($response->hasErrors() || $response->isEmpty()) {
                return;
            }

            $data = $response->getData();
            if (!is_array($data) || $data === []) {
                return;
            }

            foreach ($data as $item) {
                $row = is_array($item) ? $item : (array) $item;
                $row['id'] = $row['name'] ?? $row['id'] ?? null;

                // Flatten nested user fields for mapping
                if (isset($row['user']) && (is_array($row['user']) || is_object($row['user']))) {
                    $user = (array) $row['user'];
                    // Prefer notification email, fall back to auth email
                    $email = !empty($user['email']) ? $user['email'] : null;
                    $emailAuth = !empty($user['emailAuth']) ? $user['emailAuth'] : null;
                    $row['user_email'] = $email ?? $emailAuth;
                    $row['user_login'] = $user['name'] ?? null;
                    $row['user_title'] = $user['title'] ?? null;
                }

                // Flatten nested contact reference for mapping
                if (isset($row['contact']) && (is_array($row['contact']) || is_object($row['contact']))) {
                    $contact = (array) $row['contact'];
                    $row['contact_name'] = $contact['name'] ?? null;
                }

                $activity = Activity::fromArray($row);
                $activity->setActivityType($type);

                yield $activity;
            }

            if (count($data) < $pageSize) {
                return;
            }

            $currentOffset += $pageSize;
        }
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     */
    private function hasChanges(array $existing, array $new): bool
    {
        foreach ($new as $key => $value) {
            $existingValue = $existing[$key] ?? null;
            if ($this->normalizeValue($existingValue) != $this->normalizeValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a value for comparison to account for Daktela API transformations:
     * - Single-element arrays are unwrapped (Daktela wraps some string fields in arrays)
     * - Strings are trimmed and whitespace is collapsed (Daktela strips spaces from phones etc.)
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value) && count($value) === 1 && array_is_list($value)) {
            $value = $value[0];
        }

        if (is_string($value)) {
            return preg_replace('/\s+/', '', $value);
        }

        return $value;
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $factory
     * @return T|null
     */
    private function findEntity(string $model, string $id, callable $factory): mixed
    {
        try {
            $request = RequestFactory::buildReadSingleRequest($model, $id);
            $response = $this->client->execute($request);

            if (!$response->isSuccess() || $response->isEmpty()) {
                return null;
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? $id;

            return $factory($data);
        } catch (RequestException $e) {
            $this->logger->debug('Entity not found: {model} {id}', [
                'model' => $model,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @template T
     * @param array<string, mixed> $criteria
     * @param callable(array<string, mixed>): T $factory
     * @return T|null
     */
    private function findEntityBy(string $model, array $criteria, callable $factory): mixed
    {
        try {
            $request = RequestFactory::buildReadRequest($model);
            $request->setTake(1);

            foreach ($criteria as $field => $value) {
                $request->addFilter($field, 'eq', $value);
            }

            $response = $this->client->execute($request);

            if (!$response->isSuccess() || $response->isEmpty()) {
                return null;
            }

            $items = $response->getData();
            if (!is_array($items) || $items === []) {
                return null;
            }

            $data = is_array(reset($items)) ? reset($items) : (array) reset($items);
            $data['id'] = $data['name'] ?? null;

            return $factory($data);
        } catch (RequestException $e) {
            $this->logger->debug('Entity lookup failed: {model}', [
                'model' => $model,
                'criteria' => $criteria,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function createEntity(string $model, array $attributes): array
    {
        if (in_array($model, ['Contacts', 'Accounts'], true)) {
            $attributes['database'] = $this->database;
        }

        try {
            $request = RequestFactory::buildCreateRequest($model);
            $request->addAttributes($attributes);
            $response = $this->client->execute($request);

            if (!$response->isSuccess()) {
                throw AdapterException::createFailed(
                    $model,
                    detail: $this->formatResponseErrors($response),
                );
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? null;

            return $data;
        } catch (RequestException $e) {
            throw AdapterException::createFailed($model, $e, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function updateEntity(string $model, string $id, array $attributes): array
    {
        if (in_array($model, ['Contacts', 'Accounts'], true)) {
            $attributes['database'] = $this->database;
        }

        try {
            $request = RequestFactory::buildUpdateRequest($model);
            $request->setObjectName($id);
            $request->addAttributes($attributes);
            $response = $this->client->execute($request);

            if (!$response->isSuccess()) {
                throw AdapterException::updateFailed(
                    $model,
                    $id,
                    detail: $this->formatResponseErrors($response),
                );
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? $id;

            return $data;
        } catch (RequestException $e) {
            throw AdapterException::updateFailed($model, $id, $e, $e->getMessage());
        }
    }

    private function formatResponseErrors(\Daktela\DaktelaV6\Response\Response $response): string
    {
        $errors = $response->getErrors();
        if ($errors === []) {
            return sprintf('HTTP %d', $response->getHttpStatus());
        }

        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $messages[] = json_encode($error, JSON_UNESCAPED_UNICODE);
            } else {
                $messages[] = (string) $error;
            }
        }

        return implode('; ', $messages);
    }
}
