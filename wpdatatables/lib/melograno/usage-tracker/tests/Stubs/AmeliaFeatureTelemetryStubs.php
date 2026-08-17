<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests\Stubs;

/**
 * In-memory DB connection/statement double for EXISTS queries in telemetry tests.
 */
final class AmeliaConnectionStub
{
    /** @var list<array<string, mixed>> */
    private $fetchQueue = [];

    /** @var list<array<string, mixed>> */
    private $fetchAllResult = [];

    /** @var list<string> */
    public $queries = [];

    /**
     * @param list<array<string, mixed>> $fetchQueue
     */
    public function setFetchQueue(array $fetchQueue): void
    {
        $this->fetchQueue = $fetchQueue;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function setFetchAllResult(array $rows): void
    {
        $this->fetchAllResult = $rows;
    }

    public function prepare(string $sql): self
    {
        $this->queries[] = $sql;

        return $this;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(array $params = []): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetch()
    {
        if ($this->fetchQueue === []) {
            return ['found' => 0];
        }

        return array_shift($this->fetchQueue);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array
    {
        return $this->fetchAllResult;
    }
}

/**
 * Loads Amelia classes required by AmeliaFeatureTelemetry unit tests when Amelia is not installed.
 */
final class AmeliaFeatureTelemetryStubLoader
{
    public static function load(): void
    {
        if (!class_exists(\AmeliaBooking\Domain\Services\Settings\SettingsService::class)) {
            eval('namespace AmeliaBooking\\Domain\\Services\\Settings; class SettingsService {}');
        }

        if (!class_exists(\AmeliaBooking\Infrastructure\Common\Container::class)) {
            eval('namespace AmeliaBooking\\Infrastructure\\Common; class Container { public function get(string $id) {} public function getDatabaseConnection() {} }');
        }

        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Bookable\\ExtrasTable',
            'wp_amelia_extras'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Bookable\\PackagesTable',
            'wp_amelia_packages'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Bookable\\ResourcesTable',
            'wp_amelia_resources'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Bookable\\ServicesTable',
            'wp_amelia_services'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Coupon\\CouponsTable',
            'wp_amelia_coupons'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\CustomField\\CustomFieldsTable',
            'wp_amelia_custom_fields'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Location\\LocationsTable',
            'wp_amelia_locations'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\AppointmentsTable',
            'wp_amelia_appointments'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\CustomerBookingsTable',
            'wp_amelia_customer_bookings'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\CustomerBookingsToEventsPeriodsTable',
            'wp_amelia_customer_bookings_to_events_periods'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\EventsPeriodsTable',
            'wp_amelia_events_periods'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\EventsTable',
            'wp_amelia_events'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\EventsTagsTable',
            'wp_amelia_events_tags'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Booking\\EventsTicketsTable',
            'wp_amelia_events_tickets'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Notification\\NotificationsTable',
            'wp_amelia_notifications'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Payment\\PaymentsTable',
            'wp_amelia_payments'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\Tax\\TaxesTable',
            'wp_amelia_taxes'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\User\\Provider\\ProvidersGoogleCalendarTable',
            'wp_amelia_providers_to_google_calendar'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\User\\Provider\\ProvidersOutlookCalendarTable',
            'wp_amelia_providers_to_outlook_calendar'
        );
        self::registerTableStub(
            'AmeliaBooking\\Infrastructure\\WP\\InstallActions\\DB\\User\\UsersTable',
            'wp_amelia_users'
        );
    }

    private static function registerTableStub(string $class, string $tableName): void
    {
        if (class_exists($class)) {
            return;
        }

        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $shortName = substr($class, (int) strrpos($class, '\\') + 1);

        eval(
            'namespace ' . $namespace . ";\n"
            . 'class ' . $shortName . " {\n"
            . "    public static function getTableName() { return '" . $tableName . "'; }\n"
            . '}'
        );
    }
}
