<?php
/**
 * Ticaga Help Desk Integration Plugin for ClientExec
 * 
 * @package ClientExec
 * @subpackage Snapins
 * @author Ticaga
 * @version 1.0.4 (Final Working Version)
 */

require_once 'modules/admin/models/SnapinPlugin.php';

class PluginTicaga extends SnapinPlugin
{
    /**
     * Cached plugin settings indexed by normalized key name.
     *
     * @var array|null
     */
    private $pluginSettingsCache = null;
    /**
     * Plugin configuration variables
     * 
     * @return array Configuration array
     */
    public function getVariables()
    {
        $variables = [
            'Plugin Name' => [
                'type' => 'hidden',
                'description' => 'Used by CE to show plugin',
                'value' => 'Ticaga'
            ],
            'Description' => [
                'type' => 'hidden',
                'description' => 'Ticaga Help Desk Integration',
                'value' => 'Integrate ClientExec with Ticaga help desk system'
            ],
            'Ticaga URL' => [
                'type' => 'text',
                'description' => 'Your Ticaga installation URL (e.g., https://support.yourdomain.com)',
                'value' => '',
            ],
            'API Key' => [
                'type' => 'password',
                'description' => 'Ticaga API Key',
                'value' => '',
                'encryptable' => true
            ],
            'API Email Address' => [
                'type' => 'text',
                'description' => 'Ticaga Admin Email Address',
                'value' => '',
            ],
            'Auto Sync' => [
                'type' => 'yesno',
                'description' => 'Automatically sync customers to Ticaga',
                'value' => '1',
            ]
        ];

        return $variables;
    }

    /**
     * Initialize the plugin
     * Sets up menu mappings and permissions
     */
    function init()
    {
        $this->setDescription("Ticaga Help Desk Integration - Sync customers and manage tickets");
        $this->setPermissionLocation("clients");
        
        // Register ONLY the main view - not the sync action
        $this->addMappingForTopMenu(
            "admin",
            "clients",
            "viewTicaga",
            "Ticaga",
            "Ticaga Help Desk Integration"
        );
    }

    /**
     * Main view function - displays the Ticaga management interface
     */
    function viewTicaga()
    {
        try {
            CE_Lib::log(4, "Ticaga: Loading main view");

            $flash = $this->consumeSyncFlash();
            if (!empty($flash)) {
                if (isset($flash['success'])) {
                    $this->view->success = (bool) $flash['success'];
                }

                if (!empty($flash['message'])) {
                    if (!empty($this->view->success)) {
                        $this->view->message = $flash['message'];
                    } else {
                        $this->view->error = $flash['message'];
                    }
                }

                if (isset($flash['synced'])) {
                    $this->view->syncedCount = (int) $flash['synced'];
                }

                if (!empty($flash['errors']) && is_array($flash['errors'])) {
                    $this->view->syncErrors = $flash['errors'];
                }
            }

            // Load customers
            $customers = $this->loadCustomers();
            CE_Lib::log(4, "Ticaga: Loaded " . count($customers) . " customers");
            
            // Pass data to the view
            $this->view->customers = $customers;
            $this->view->ticagaUrl = $this->getSetting('Ticaga URL');
            $this->view->apiKey = $this->getSetting('API Key');
            $this->view->apiEmail = $this->getSetting('API Email Address');
            $this->view->autoSync = $this->getSetting('Auto Sync');
            $this->view->syncUrl = $this->buildSyncUrl();
            $this->view->settingsUrl = $this->buildSettingsUrl();
            
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Error: " . $e->getMessage());
            $this->view->error = $e->getMessage();
        }
    }

    /**
     * Sync action handler
     */
    function ticagaSync()
    {
        $result = null;
        $errorMessage = null;

        try {
            CE_Lib::log(4, "Ticaga: Sync action initiated");

            $bulkSync = isset($_REQUEST['bulk_sync']) ? (int)$_REQUEST['bulk_sync'] : 0;
            $selectedCustomers = isset($_REQUEST['customer_ids']) ? $_REQUEST['customer_ids'] : [];

            if (!is_array($selectedCustomers)) {
                $selectedCustomers = [$selectedCustomers];
            }

            if ($bulkSync) {
                CE_Lib::log(4, "Ticaga: Starting bulk sync");
                $result = $this->performBulkSync($selectedCustomers);
            } else {
                CE_Lib::log(4, "Ticaga: Starting single customer sync");
                $customerId = isset($_REQUEST['customer_id']) ? (int)$_REQUEST['customer_id'] : 0;
                $result = $this->syncSingleCustomer($customerId);
            }

        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Sync Error: " . $e->getMessage());
            $errorMessage = $e->getMessage();
        }

        $flash = $this->prepareSyncFlash($result, $errorMessage);
        if (!empty($flash)) {
            $this->ensureSession();
            $_SESSION['ticaga_sync_flash'] = $flash;
        }

        $this->redirectToMainView();
    }

    /**
     * Build the sync URL for form submissions
     */
    private function buildSyncUrl()
    {
        return '/admin/index.php?fuse=admin&view=viewsnapin&controller=snapins&plugin=ticaga&action=ticagaSync';
    }

    /**
     * Build the URL that renders the Ticaga snapin view.
     *
     * @return string
     */
    private function buildViewUrl()
    {
        return '/admin/index.php?fuse=admin&view=viewsnapin&controller=snapins&plugin=ticaga';
    }

    /**
     * Build the URL that opens the Ticaga snapin settings configuration page.
     *
     * @return string
     */
    private function buildSettingsUrl()
    {
        return '/admin/index.php?fuse=admin&controller=settings&view=snapinsettings&plugin=ticaga&settings=plugins_snapins&type=Snapins';
    }

    /**
     * Load customers from ClientExec
     */
    private function loadCustomers()
    {
        $customers = [];
        
        try {
            $query = "SELECT id, firstname, lastname, email, organization 
                      FROM users 
                      WHERE status = 1 
                      ORDER BY lastname, firstname 
                      LIMIT 100";
            
            $result = $this->db->query($query);
            
            while ($row = $result->fetch()) {
                $customers[] = [
                    'id' => $row['id'],
                    'name' => trim($row['firstname'] . ' ' . $row['lastname']),
                    'email' => $row['email'],
                    'organization' => $row['organization']
                ];
            }
            
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga: Error loading customers - " . $e->getMessage());
        }
        
        return $customers;
    }

    /**
     * Perform bulk synchronization
     */
    private function performBulkSync($customerIds = [])
    {
        CE_Lib::log(4, "Ticaga: Performing bulk sync");
        
        $apiKey = $this->getSetting('API Key');
        $apiEmail = $this->getSetting('API Email Address');
        $ticagaUrl = $this->getSetting('Ticaga URL');
        
        if (empty($apiKey) || empty($ticagaUrl) || empty($apiEmail)) {
            return [
                'success' => false,
                'message' => 'Ticaga URL, API Key, and API Email Address must be configured before syncing.'
            ];
        }
        
        $customers = $this->loadCustomers();
        
        if (!empty($customerIds)) {
            $customers = array_filter($customers, function($customer) use ($customerIds) {
                return in_array($customer['id'], $customerIds);
            });
        }
        
        $synced = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($customers as $customer) {
            try {
                $syncResult = $this->syncCustomerToTicaga($customer, $ticagaUrl, $apiKey, $apiEmail);
                
                if ($syncResult['success']) {
                    $synced++;
                } else {
                    $failed++;
                    $errors[] = "Failed to sync {$customer['name']}: {$syncResult['error']}";
                }
                
            } catch (Exception $e) {
                $failed++;
                $errors[] = "Error syncing {$customer['name']}: " . $e->getMessage();
                CE_Lib::log(2, "Ticaga: Sync error for customer {$customer['id']} - " . $e->getMessage());
            }
        }
        
        $message = "Sync completed: {$synced} customers synced successfully";
        if ($failed > 0) {
            $message .= ", {$failed} failed";
        }
        
        CE_Lib::log(4, "Ticaga: " . $message);
        
        return [
            'success' => true,
            'message' => $message,
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Sync a single customer
     */
    private function syncSingleCustomer($customerId)
    {
        CE_Lib::log(4, "Ticaga: Syncing single customer ID: {$customerId}");
        
        $apiKey = $this->getSetting('API Key');
        $apiEmail = $this->getSetting('API Email Address');
        $ticagaUrl = $this->getSetting('Ticaga URL');
        
        if (empty($apiKey) || empty($ticagaUrl) || empty($apiEmail)) {
            return [
                'success' => false,
                'message' => 'Ticaga URL, API Key, and API Email Address must be configured before syncing.'
            ];
        }
        
        $query = "SELECT id, firstname, lastname, email, organization 
                  FROM users 
                  WHERE id = ? 
                  LIMIT 1";
        
        $result = $this->db->query($query, $customerId);
        $customer = $result->fetch();
        
        if (!$customer) {
            return [
                'success' => false,
                'message' => 'Customer not found'
            ];
        }
        
        $customerData = [
            'id' => $customer['id'],
            'name' => trim($customer['firstname'] . ' ' . $customer['lastname']),
            'email' => $customer['email'],
            'organization' => $customer['organization']
        ];
        
        try {
            $syncResult = $this->syncCustomerToTicaga($customerData, $ticagaUrl, $apiKey, $apiEmail);
            
            if ($syncResult['success']) {
                return [
                    'success' => true,
                    'message' => "Customer {$customerData['name']} synced successfully",
                    'synced' => 1
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Failed to sync customer: {$syncResult['error']}"
                ];
            }
            
        } catch (Exception $e) {
            CE_Lib::log(2, "Ticaga: Error syncing customer {$customerId} - " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Sync customer data to Ticaga via API
     */
    private function syncCustomerToTicaga($customer, $ticagaUrl, $apiKey, $apiEmail)
    {
        $apiUrl = rtrim($ticagaUrl, '/') . '/api/customers/sync';
        
        $postData = [
            'external_id' => $customer['id'],
            'name' => $customer['name'],
            'email' => $customer['email'],
            'company' => $customer['organization']
        ];
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'X-Admin-Email: ' . $apiEmail,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For demo/test environments
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            CE_Lib::log(2, "Ticaga: cURL error - " . $curlError);
            return [
                'success' => false,
                'error' => $curlError
            ];
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            CE_Lib::log(4, "Ticaga: Successfully synced customer {$customer['id']}");
            return [
                'success' => true
            ];
        } else {
            CE_Lib::log(2, "Ticaga: API error (HTTP {$httpCode}) - " . $response);
            return [
                'success' => false,
                'error' => "API returned HTTP {$httpCode}: " . substr($response, 0, 100)
            ];
        }
    }

    /**
     * Get a plugin setting value - WORKING VERSION
     * Uses the exact key format shown in diagnostics: plugin_ticaga_[Setting_Name]
     */
    private function getSetting($key)
    {
        $variants = $this->generateSettingKeyVariants($key);

        // Try via settings object first using any key variation that might exist.
        if (!empty($variants) && isset($this->settings) && is_object($this->settings)) {
            try {
                if (method_exists($this->settings, 'get')) {
                    foreach ($variants as $variant) {
                        $settingKey = 'plugin_ticaga_' . $variant;
                        $value = $this->settings->get($settingKey);
                        if ($value !== null && $value !== '') {
                            return $value;
                        }
                    }
                }
            } catch (Exception $e) {
                CE_Lib::log(2, "Ticaga: Settings object error: " . $e->getMessage());
            }
        }

        // Load cached settings from the database and attempt to resolve the requested key.
        $this->buildPluginSettingsCache();
        foreach ($variants as $variant) {
            if (isset($this->pluginSettingsCache[$variant]) && $this->pluginSettingsCache[$variant] !== '') {
                return $this->pluginSettingsCache[$variant];
            }
        }

        // As a final attempt, look up each possible key variation in the database.
        foreach ($variants as $variant) {
            try {
                $query = "SELECT value FROM setting WHERE name = ?";
                $result = $this->db->query($query, 'plugin_ticaga_' . $variant);
                if ($result && $row = $result->fetch()) {
                    $value = $row['value'];
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            } catch (Exception $e) {
                CE_Lib::log(2, "Ticaga: DB error getting setting ({$variant}): " . $e->getMessage());
            }
        }

        // Return default value
        $variables = $this->getVariables();
        return isset($variables[$key]['value']) ? $variables[$key]['value'] : '';
    }

    /**
     * Normalize a setting key to the lowercase underscore format used by ClientExec.
     *
     * @param string $key
     * @return string
     */
    private function normalizeSettingKey($key)
    {
        $normalizedKey = strtolower(trim($key));
        $normalizedKey = preg_replace('/[^a-z0-9]+/', '_', $normalizedKey);
        return trim($normalizedKey, '_');
    }

    /**
     * Redirect the browser back to the main snapin view.
     */
    private function redirectToMainView()
    {
        $viewUrl = $this->buildViewUrl();

        if (!headers_sent()) {
            header('Location: ' . $viewUrl);
            exit;
        }

        echo '<script>window.location.href=' . json_encode($viewUrl) . ';</script>';
        exit;
    }

    /**
     * Build a flash array from the sync result for display after redirect.
     *
     * @param array|null $result
     * @param string|null $errorMessage
     * @return array
     */
    private function prepareSyncFlash($result, $errorMessage)
    {
        $flash = [];

        if (is_array($result)) {
            $flash = $result;
        }

        if (!isset($flash['message']) || $flash['message'] === '') {
            if (!empty($errorMessage)) {
                $flash['message'] = $errorMessage;
            }
        }

        if (!isset($flash['success'])) {
            $flash['success'] = is_array($result) ? !empty($result['success']) : false;
        } else {
            $flash['success'] = (bool) $flash['success'];
        }

        if (!empty($errorMessage) && empty($flash['message'])) {
            $flash['message'] = $errorMessage;
        }

        if (!empty($errorMessage) && $flash['success']) {
            $flash['success'] = false;
        }

        if (!isset($flash['errors'])) {
            $flash['errors'] = [];
        } elseif (!is_array($flash['errors'])) {
            $flash['errors'] = (array) $flash['errors'];
        }

        if (isset($flash['message']) && !is_string($flash['message'])) {
            $flash['message'] = (string) $flash['message'];
        }

        $hasContent = !empty($flash['success']) || !empty($flash['message'])
            || (isset($flash['synced']) && $flash['synced'])
            || !empty($flash['errors']);

        return $hasContent ? $flash : [];
    }

    /**
     * Retrieve and clear the sync flash message from the session.
     *
     * @return array
     */
    private function consumeSyncFlash()
    {
        $this->ensureSession();
        if (!isset($_SESSION['ticaga_sync_flash'])) {
            return [];
        }

        $flash = $_SESSION['ticaga_sync_flash'];
        unset($_SESSION['ticaga_sync_flash']);

        if (!is_array($flash)) {
            return [];
        }

        return $flash;
    }

    /**
     * Populate the settings cache with any values stored for the Ticaga plugin.
     */
    private function buildPluginSettingsCache()
    {
        if ($this->pluginSettingsCache !== null) {
            return;
        }

        $this->pluginSettingsCache = [];

        try {
            $query = "SELECT name, value FROM setting WHERE name LIKE ?";
            $result = $this->db->query($query, 'plugin_ticaga_%');

            while ($result && ($row = $result->fetch())) {
                $name = $row['name'];
                $value = $row['value'];

                if (stripos($name, 'plugin_ticaga_') !== 0) {
                    continue;
                }

                $rawKey = substr($name, strlen('plugin_ticaga_'));
                $variants = $this->generateSettingKeyVariants($rawKey);

                foreach ($variants as $variant) {
                    if ($variant === '') {
                        continue;
                    }

                    if (!array_key_exists($variant, $this->pluginSettingsCache) || $this->pluginSettingsCache[$variant] === '') {
                        $this->pluginSettingsCache[$variant] = $value;
                    }
                }
            }
        } catch (Exception $e) {
            CE_Lib::log(2, "Ticaga: DB error building settings cache: " . $e->getMessage());
        }
    }

    /**
     * Generate possible key variants for a plugin setting to accommodate differences in how
     * ClientExec persists configuration values (spaces, case, underscores, etc.).
     *
     * @param string $key
     * @return array
     */
    private function generateSettingKeyVariants($key)
    {
        $variants = [];

        $trimmed = trim((string) $key);
        if ($trimmed === '') {
            return $variants;
        }

        $base = [];
        $base[] = $trimmed;
        $base[] = str_replace(' ', '_', $trimmed);
        $base[] = str_replace([' ', '-'], '_', $trimmed);
        $base[] = str_replace([' ', '-', '_'], '', $trimmed);

        $lowercaseVariants = [];
        foreach ($base as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $lowercaseVariants[] = strtolower($candidate);
        }

        $base = array_merge($base, $lowercaseVariants);

        $normalized = $this->normalizeSettingKey($trimmed);
        if ($normalized !== '') {
            $base[] = $normalized;
            $base[] = str_replace('_', '', $normalized);
        }

        $unique = [];
        foreach ($base as $candidate) {
            $candidate = trim($candidate);
            $candidate = trim($candidate, '_');
            if ($candidate === '') {
                continue;
            }

            if (!isset($unique[$candidate])) {
                $unique[$candidate] = true;
            }
        }

        return array_keys($unique);
    }

    /**
     * Ensure a PHP session is available before accessing $_SESSION.
     */
    private function ensureSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
