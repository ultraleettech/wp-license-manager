<?php

namespace Ultraleet\WP\LicenseManager;

use Psr\Log\LoggerInterface;

class LicenseManager
{
    const DEMO_PACKAGE = 'DEMO';
    const SECS_PER_DAY = 24*60*60;

    private $pluginId;
    private $pluginName;
    private $serverUrl;
    private $secretKey;
    private $demoPeriod = 0;
    private $demoStart;
    private $licenseKey;
    private $licenseData;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * LicenseManager constructor.
     *
     * @param string $pluginId
     * @param string $pluginName
     * @param string $serverUrl
     * @param string $secretKey
     */
    public function __construct(string $pluginId, string $pluginName, string $serverUrl, string $secretKey)
    {
        $this->pluginId = $pluginId;
        $this->pluginName = $pluginName;
        $this->serverUrl = $serverUrl;
        $this->secretKey = $secretKey;
    }

    /**
     * Enable demo period.
     *
     * @param int $period Demo period in days.
     */
    public function setDemoPeriod(int $period)
    {
        $this->demoPeriod = $period + 1;
        $optionName = "{$this->pluginId}_demo_start";
        if (!$this->demoStart = get_option($optionName)) {
            add_option($optionName, $this->demoStart = date('Y-m-d'));
        }
    }

    /**
     * Set logger instance.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Invoke logger method.
     *
     * @param string $level
     * @param $message
     * @param array $context
     */
    public function log($level, $message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->log($level, "LM: $message", $context);
        }
    }

    /**
     * Activate the license. Returns true on success, false on failure.
     *
     * @param string $key
     * @return bool
     */
    public function activate(string $key)
    {
        $licenseData = $this->query('slm_activate', [
            'license_key' => $key,
            'registered_domain' => $_SERVER['SERVER_NAME'],
            'item_reference' => $this->pluginName,
        ]);
        if (!$licenseData || $licenseData['result'] !== 'success') {
            return false;
        }
        $this->setLicenseKey($key);
        return true;
    }

    /**
     * Perform a license manager API query.
     *
     * @param string $action
     * @param array $args
     * @return array|bool|mixed|object
     */
    protected function query(string $action, array $args)
    {
        $args = array_merge([
            'slm_action' => $action,
            'secret_key' => $this->secretKey,
        ], $args);
        $query = esc_url_raw(add_query_arg($args, $this->serverUrl));
        $response = wp_remote_get($query, ['timeout' => 20, 'sslverify' => false]);
        if (is_wp_error($response)) {
            return false;
        }
        $result = json_decode(wp_remote_retrieve_body($response), true);
        $this->log('debug', 'API query', ['args' => $args, 'result' => $result]);
        return $result;
    }

    /**
     * Return package ID string (product ref or DEMO).
     *
     * @return string
     */
    public function getPackage(): string
    {
        if (($licenseData = $this->getLicenseData()) && $licenseData['status'] === 'active') {
            return $licenseData['product_ref'];
        }
        return $this->isDemo() ? static::DEMO_PACKAGE : false;
    }

    /**
     * Check if demo is active or if there is an active license installed.
     *
     * @return bool
     */
    public function isActive()
    {
        $licenseData = $this->getLicenseData();
        return ($licenseData && $licenseData['status'] === 'active') || $this->isDemo();
    }

    /**
     * @return bool
     */
    public function isDemo(): bool
    {
        if ($this->demoPeriod) {
            return $this->getDemoDaysLeft() > 0;
        }
        return false;
    }

    public function getDemoDaysLeft(): int
    {
        $expire = strtotime($this->demoStart) + $this->demoPeriod * static::SECS_PER_DAY;
        $secondsLeft = max(0, $expire - time());
        return $secondsLeft / static::SECS_PER_DAY;
    }

    /**
     * @return mixed
     */
    public function getLicenseData()
    {
        if (!isset($this->licenseData)) {
            $this->licenseData = get_transient("{$this->pluginId}_license_data");
            if (!$this->licenseData) {
                $this->checkLicense();
            }
        }
        return $this->licenseData;
    }

    /**
     * @return bool
     */
    protected function checkLicense()
    {
        if ($licenseKey = $this->getLicenseKey()) {
            $licenseData = $this->query('slm_check', [
                'license_key' => $licenseKey,
            ]);
            if (!$licenseData || $licenseData['result'] !== 'success') {
                return false;
            }
            $this->licenseData = $licenseData;
            set_transient("{$this->pluginId}_license_data", $licenseData, 24 * 60 * 60);
            return $licenseData['status'] === 'active';
        }
        return false;
    }

    /**
     * @param string $key
     * @return LicenseManager
     */
    public function setLicenseKey(string $key): self
    {
        $this->licenseKey = $key;
        update_option("{$this->pluginId}_license_key", $key, false);
        return $this;
    }

    /**
     * @return string
     */
    public function getLicenseKey(): string
    {
        if (!$this->licenseKey) {
            $this->licenseKey = get_option("{$this->pluginId}_license_key", '');
        }
        return $this->licenseKey;
    }

    /**
     * @return bool
     */
    public function deactivate()
    {
        if ($licenseKey = $this->getLicenseKey()) {
            $response = $this->query('slm_deactivate', [
                'license_key' => $licenseKey,
                'registered_domain' => $_SERVER['SERVER_NAME'],
                'item_reference' => $this->pluginName,
            ]);
            if (!$response) {
                return false;
            }
            delete_option("{$this->pluginId}_license_key");
            delete_transient("{$this->pluginId}_license_data");
            unset($this->licenseKey);
        }
        return true;
    }
}
