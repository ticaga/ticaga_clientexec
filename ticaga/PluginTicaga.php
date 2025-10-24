<?php

/**
 * Ticaga Integration Snapin for ClientExec
 * 
 * Syncs customers and creates tickets automatically between ClientExec and Ticaga
 * Employees use Ticaga directly for ticket management
 */

require_once 'modules/admin/models/SnapinPlugin.php';

class PluginTicaga extends SnapinPlugin
{
    /** @var mixed */
    private $db;
    // Event listeners for hooks
    public $listeners = [
        ["Customer-Create", "onCustomerCreate"]
    ];
    
    /**
     * Initialize plugin - called when plugin loads
     */
    public function init()
    {
        // Add admin menu item for sync tools
        $this->addMappingForTopMenu('admin', 'clients', 'ticagaSync', 'Ticaga Sync', 'Sync customers to Ticaga');
    }
    
    /**
     * Admin sync page view
     */
    public function ticagaSync()
    {
        $this->view->syncResult = null;
        
        // Get all customers for dropdown
        $this->view->customers = $this->getAllCustomersForDropdown();
        
        // Handle bulk sync request
        if (isset($_POST['bulk_sync'])) {
            $this->view->syncResult = $this->bulkSyncCustomers();
        }
        
        // Handle single customer sync request
        if (isset($_POST['sync_customer'])) {
            $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
            // Fallback to manual input if dropdown not used
            if (!$customerId && isset($_POST['customer_id_manual'])) {
                $customerId = (int)$_POST['customer_id_manual'];
            }
            
            if ($customerId > 0) {
                $result = $this->registerCustomer($customerId);
                $this->view->syncResult = [
                    'type' => 'single',
                    'customer_id' => $customerId,
                    'result' => $result
                ];
            }
        }
    }
    
    /**
     * Get all customers for dropdown
     */
    private function getAllCustomersForDropdown()
    {
        try {
            // Use ClientExec's database connection properly
            $db = $this->getDbConnection();
            
            $query = "SELECT id, firstName, lastName, email, organization 
                      FROM users 
                      WHERE status != 'Inactive' 
                      ORDER BY lastName, firstName 
                      LIMIT 500";
            
            $stmt = $db->query($query);
            
            $customers = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = trim(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? ''));
                if (empty($name)) {
                    $name = 'Unknown';
                }
                $org = !empty($row['organization']) ? ' - ' . $row['organization'] : '';
                $email = !empty($row['email']) ? $row['email'] : 'no email';
                $customers[] = [
                    'id' => $row['id'],
                    'display' => "#{$row['id']} - {$name}{$org} ({$email})"
                ];
            }
            
            CE_Lib::log(4, "Ticaga: Loaded " . count($customers) . " customers for dropdown");
            return $customers;
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga: Error fetching customers for dropdown: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get database connection
     */
    private function getDbConnection()
    {
        if ($this->db === null) {
            $this->db = CE_Lib::getDb();
        }

        return $this->db;
    }
    
    /**
     * Plugin configuration fields
     */
    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => '',
                'value' => 'Ticaga Integration'
            ],
            lang('Ticaga URL') => [
                'type' => 'text',
                'description' => lang('Your Ticaga installation URL (e.g., https://yourdomain.com)'),
                'value' => ''
            ],
            lang('Admin Email') => [
                'type' => 'text',
                'description' => lang('Admin email address for API authentication'),
                'value' => ''
            ],
            lang('API Token') => [
                'type' => 'password',
                'description' => lang('API Token from Ticaga'),
                'value' => '',
                'encryptable' => true
            ],
            lang('Auto Sync Customers') => [
                'type' => 'yesno',
                'description' => lang('Automatically sync new customers to Ticaga'),
                'value' => '1'
            ],
            lang('Default Department') => [
                'type' => 'text',
                'description' => lang('Default department ID for new tickets'),
                'value' => '1'
            ]
        ];
        
        return $variables;
    }
    
    /**
     * Get config values
     */
    private function getConfig($key)
    {
        return $this->settings->get("plugin_ticaga_$key");
    }
    
    /**
     * Make API request to Ticaga
     */
    private function makeRequest($endpoint, $method = 'GET', $data = [])
    {
        $ticagaUrl = rtrim($this->getConfig('Ticaga URL'), '/');
        $adminEmail = $this->getConfig('Admin Email');
        $apiToken = $this->getConfig('API Token');
        
        if (empty($ticagaUrl) || empty($adminEmail) || empty($apiToken)) {
            throw new Exception("Ticaga plugin not configured properly");
        }
        
        $url = $ticagaUrl . '/api/' . ltrim($endpoint, '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Add authentication headers
        $headers = [
            'Content-Type: application/json',
            'X-Admin-Email: ' . $adminEmail,
            'X-API-Token: ' . $apiToken
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: $error");
        }
        
        if ($httpCode >= 400) {
            throw new Exception("API Error: HTTP $httpCode - $response");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Bulk sync all customers to Ticaga
     */
    public function bulkSyncCustomers()
    {
        try {
            $db = $this->getDbConnection();

            $query = "SELECT id FROM users WHERE status != 'Inactive' ORDER BY id";
            $result = $db->query($query);
            
            $synced = 0;
            $failed = 0;
            $skipped = 0;
            $errors = [];
            
            while ($row = $result->fetch()) {
                $customerId = $row['id'];
                
                try {
                    $syncResult = $this->registerCustomer($customerId);
                    
                    if (isset($syncResult['error'])) {
                        $failed++;
                        $errors[] = "Customer $customerId: " . $syncResult['error'];
                    } elseif (isset($syncResult['id'])) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                    
                    // Small delay to avoid hammering the API
                    usleep(100000); // 0.1 second delay
                    
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = "Customer $customerId: " . $e->getMessage();
                }
            }
            
            CE_Lib::log(4, "Ticaga Bulk Sync: $synced synced, $failed failed, $skipped skipped");
            
            return [
                'type' => 'bulk',
                'synced' => $synced,
                'failed' => $failed,
                'skipped' => $skipped,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Bulk Sync Error: " . $e->getMessage());
            return [
                'type' => 'bulk',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Event: Customer Created - Auto sync to Ticaga
     */
    public function onCustomerCreate($event)
    {
        if (is_array($event)) {
            $params = $event;
        } else {
            $params = $event->getParams();
        }
        
        if ($this->getConfig('Auto Sync Customers') == '1') {
            $customerId = $params['customer_id'] ?? null;
            if ($customerId) {
                CE_Lib::log(4, "Ticaga: Auto-syncing new customer $customerId");
                $this->registerCustomer($customerId);
            }
        }
    }
    
    /**
     * Register a new customer in Ticaga
     */
    public function registerCustomer($customerId)
    {
        try {
            $customer = new User($customerId);
            
            $data = [
                'email' => $customer->getEmail(),
                'name' => $customer->getFirstName() . ' ' . $customer->getLastName(),
                'company' => $customer->getOrganization()
            ];
            
            $result = $this->makeRequest('register', 'POST', $data);
            
            // Link ClientExec customer to Ticaga account
            if (isset($result['id'])) {
                CE_Lib::log(4, "Ticaga: Customer $customerId registered with Ticaga ID: " . $result['id']);
                $this->assignBillingId($result['id'], $customerId);
            }
            
            return $result;
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Register Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Assign billing ID (link ClientExec customer to Ticaga)
     */
    public function assignBillingId($ticagaId, $billingId)
    {
        try {
            $data = [
                'ticaga_id' => $ticagaId,
                'billing_id' => $billingId
            ];
            
            $result = $this->makeRequest('customers/billing', 'POST', $data);
            CE_Lib::log(4, "Ticaga: Linked customer $billingId to Ticaga ID $ticagaId");
            return $result;
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Billing ID Assignment Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get customer information from Ticaga
     */
    public function getCustomerInfo($ticagaId)
    {
        try {
            return $this->makeRequest("customers/get/$ticagaId");
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Get Customer Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get all departments
     */
    public function getDepartments()
    {
        try {
            return $this->makeRequest('departments');
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Get Departments Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Create a ticket in Ticaga
     */
    public function createTicket($customerId, $subject, $message, $departmentId = null)
    {
        try {
            if ($departmentId === null) {
                $departmentId = $this->getConfig('Default Department');
            }
            
            $data = [
                'customer_id' => $customerId,
                'subject' => $subject,
                'message' => $message,
                'department_id' => $departmentId,
                'priority' => 'normal',
                'status' => 'open'
            ];
            
            $result = $this->makeRequest('tickets/create', 'POST', $data);
            CE_Lib::log(4, "Ticaga: Ticket created for customer $customerId");
            return $result;
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Create Ticket Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get a specific ticket
     */
    public function getTicket($ticagaId, $ticketId)
    {
        try {
            return $this->makeRequest("tickets/get/$ticagaId/$ticketId");
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Get Ticket Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get all tickets for a customer
     */
    public function getAllTickets($ticagaId)
    {
        try {
            return $this->makeRequest("tickets/get/all/$ticagaId");
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Get All Tickets Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get responses for a ticket
     */
    public function getResponses($ticketNumber)
    {
        try {
            return $this->makeRequest("responses/get/$ticketNumber");
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Get Responses Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Create a response to a ticket
     */
    public function createResponse($ticketNumber, $message, $customerId = null)
    {
        try {
            $data = [
                'ticket_number' => $ticketNumber,
                'message' => $message
            ];
            
            if ($customerId) {
                $data['customer_id'] = $customerId;
            }
            
            $result = $this->makeRequest('responses/create', 'POST', $data);
            CE_Lib::log(4, "Ticaga: Response added to ticket $ticketNumber");
            return $result;
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Create Response Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Close a ticket
     */
    public function closeTicket($customerId, $ticketId)
    {
        try {
            $data = [
                'customer_id' => $customerId,
                'ticket_id' => $ticketId
            ];
            
            $result = $this->makeRequest('tickets/close', 'POST', $data);
            CE_Lib::log(4, "Ticaga: Ticket $ticketId closed");
            return $result;
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Close Ticket Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Login a customer
     */
    public function loginCustomer($email, $password)
    {
        try {
            $data = [
                'email' => $email,
                'password' => $password
            ];
            
            return $this->makeRequest('login', 'POST', $data);
        } catch (Exception $e) {
            CE_Lib::log(1, "Ticaga Login Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
?>
