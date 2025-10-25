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
        try {
            CE_Lib::log(4, "Ticaga: Sync action initiated");
            
            $bulkSync = isset($_REQUEST['bulk_sync']) ? (int)$_REQUEST['bulk_sync'] : 0;
            $selectedCustomers = isset($_REQUEST['customer_ids']) ? $_REQUEST['customer_ids'] : [];
            
            if ($bulkSync) {
                CE_Lib::log(4, "Ticaga: Starting bulk sync");
                $result = $this->performBulkSync($selectedCustomers);
            } else {
                CE_Lib::log(4, "Ticaga: Starting single customer sync");
                $customerId = isset($_REQUEST['customer_id']) ? (int)$_REQUEST['customer_id'] : 0;
                $result = $this->syncSingleCustomer($customerId);
            }
            
            $this->view->syncResult = $result;
            $this->view->success = $result['success'];
            $this->view->message = $result['message'];
            $this->view->syncedCount = isset($result['synced']) ? $result['synced'] : 0;
            
            $this->view->customers = $this->loadCustomers();
            $this->view->ticagaUrl = $this->getSetting('Ticaga URL');
            
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Sync Error: " . $e->getMessage());
            $this->view->error = $e->getMessage();
            $this->view->success = false;
        }
    }

    /**
     * Build the sync URL for form submissions
     */
    private function buildSyncUrl()
    {
        return '/admin/index.php?fuse=admin&view=viewsnapin&controller=snapins&plugin=ticaga&action=ticagaSync';
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
        // Build the key exactly as ClientExec stores it:
        // plugin_ticaga_ (lowercase) + Setting Name (with underscores for spaces)
        // Normalize the key the same way ClientExec stores it in the
        // "setting" table (all lowercase with underscores)
        $normalizedKey = strtolower(trim($key));
        $normalizedKey = preg_replace('/[^a-z0-9]+/', '_', $normalizedKey);
        $normalizedKey = trim($normalizedKey, '_');

        $settingKey = 'plugin_ticaga_' . $normalizedKey;
        
        // Try via settings object first
        if (isset($this->settings) && is_object($this->settings)) {
            try {
                if (method_exists($this->settings, 'get')) {
                    $value = $this->settings->get($settingKey);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            } catch (Exception $e) {
                CE_Lib::log(2, "Ticaga: Settings object error: " . $e->getMessage());
            }
        }
        
        // Fallback: Direct database query
        try {
            $query = "SELECT value FROM setting WHERE name = ?";
            $result = $this->db->query($query, $settingKey);
            if ($result && $row = $result->fetch()) {
                return $row['value'];
            }
        } catch (Exception $e) {
            CE_Lib::log(2, "Ticaga: DB error getting setting: " . $e->getMessage());
        }
        
        // Return default value
        $variables = $this->getVariables();
        return isset($variables[$key]['value']) ? $variables[$key]['value'] : '';
    }
}
