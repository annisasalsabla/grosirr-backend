<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ChangePasswordController;

/*
|--------------------------------------------------------------------------
| API Routes - Public (Tidak Perlu Login)
|--------------------------------------------------------------------------
*/
Route::post('/register', [RegisterController::class, 'register']);
Route::post('/verify-otp', [RegisterController::class, 'verifyOtp']);
Route::post('/login', [LoginController::class, 'login']);
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);

// Test route to trigger error handler
Route::get('/test-error', function () {
    throw new \Exception('Test error triggered at ' . date('Y-m-d H:i:s'));
});

/*
|--------------------------------------------------------------------------
| DEV ROUTES - Testing Only (Non-Production)
|--------------------------------------------------------------------------
*/
// Only active if APP_ENV != 'production'
if (!app()->environment('production')) {
    Route::post('/dev/simulate-midtrans-payment/{id}', [App\Http\Controllers\DevController::class, 'simulateMidtransPayment']);
    Route::post('/dev/test-push-notification', [App\Http\Controllers\Dev\TestPushController::class, 'send']);
}

/*
|--------------------------------------------------------------------------
| API Routes - Public (No Authentication - Midtrans Callback)
|--------------------------------------------------------------------------
*/
Route::post('/payment/midtrans-callback', [App\Http\Controllers\PaymentCallbackController::class, 'handleCallback']);

/*
|--------------------------------------------------------------------------
| API Routes - Notifications (Testing) - Admin Only
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('notifications')->group(function () {
    // Test kirim notifikasi stok menipis
    Route::get('/test/low-stock', [App\Http\Controllers\Admin\NotificationController::class, 'testLowStock']);
    // Test kirim notifikasi piutang
    Route::get('/test/receivable', [App\Http\Controllers\Admin\NotificationController::class, 'testReceivable']);
});

/*
|--------------------------------------------------------------------------
| API Routes - Protected (Perlu Login & Token)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    
    // ==================== LOGOUT & CHANGE PASSWORD (Semua Role) ====================
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me', [LoginController::class, 'me']);
    Route::post('/change-password', [ChangePasswordController::class, 'changePassword']);
    
    /*
    |--------------------------------------------------------------------------
    | OWNER ONLY ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware(['role:owner'])->prefix('owner')->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Owner\DashboardController::class, 'index']);
        
        // Profile
        Route::get('/profile', [App\Http\Controllers\Owner\ProfileController::class, 'show']);
        Route::put('/profile', [App\Http\Controllers\Owner\ProfileController::class, 'update']);
        
        // Admin Management
        Route::apiResource('admins', App\Http\Controllers\Owner\AdminManagementController::class);
        Route::patch('/admins/{id}/toggle-active', [App\Http\Controllers\Owner\AdminManagementController::class, 'toggleActive']);
        
        // Products (View Only)
        Route::get('/products', [App\Http\Controllers\Owner\ProductController::class, 'index']);
        Route::get('/products/list', [App\Http\Controllers\Owner\ProductController::class, 'listByCategory']);
        Route::get('/products/{id}', [App\Http\Controllers\Owner\ProductController::class, 'show']);
        
        // Stock (View Only)
        Route::get('/stocks', [App\Http\Controllers\Owner\StockController::class, 'index']);
        Route::get('/stocks/history', [App\Http\Controllers\Owner\StockController::class, 'history']);
        
        // Reports
        Route::get('/reports/sales', [App\Http\Controllers\Owner\ReportController::class, 'salesReport']);
        Route::get('/reports/sales/pdf', [App\Http\Controllers\Owner\ReportController::class, 'exportSalesPDF']);
        Route::get('/reports/sales/excel', [App\Http\Controllers\Owner\ReportController::class, 'exportSalesExcel']);
        Route::get('/reports/profit', [App\Http\Controllers\Owner\ReportController::class, 'profitReport']);
        Route::get('/reports/bad-products', [App\Http\Controllers\Owner\ReportController::class, 'badProductReport']);
        Route::get('/reports/receivables', [App\Http\Controllers\Owner\ReportController::class, 'receivableReport']);
        Route::get('/reports/payables', [App\Http\Controllers\Owner\ReportController::class, 'payableReport']);

        // Penjualan (Harian, Mingguan, Bulanan)
        Route::get('/sales/daily', [App\Http\Controllers\Owner\SalesController::class, 'daily']);
        Route::get('/sales/weekly', [App\Http\Controllers\Owner\SalesController::class, 'weekly']);
        Route::get('/sales/monthly', [App\Http\Controllers\Owner\SalesController::class, 'monthly']);

        // Laba (Harian, Mingguan, Bulanan)
        Route::get('/profit/daily', [App\Http\Controllers\Owner\ProfitController::class, 'daily']);
        Route::get('/profit/weekly', [App\Http\Controllers\Owner\ProfitController::class, 'weekly']);
        Route::get('/profit/monthly', [App\Http\Controllers\Owner\ProfitController::class, 'monthly']);

        // ==================== BARANG RUSAK (PEMANTAUAN) ====================
        Route::get('/damaged-goods', [App\Http\Controllers\Owner\DamagedGoodsController::class, 'index']);
        Route::get('/bad-products/supplier-comparison', [App\Http\Controllers\Owner\DamagedGoodsController::class, 'getSupplierComparison']);

        // ==================== PIUTANG PELANGGAN (PEMANTAUAN) ====================
        Route::get('/customer-receivables', [App\Http\Controllers\Owner\CustomerReceivableController::class, 'index']);

        // ==================== HUTANG KE SUPPLIER (PEMANTAUAN) ====================
        Route::get('/supplier-payables', [App\Http\Controllers\Owner\SupplierPayableController::class, 'index']);

        // Settings - Toggle Payment Methods
        Route::get('/settings', [App\Http\Controllers\Owner\SettingsController::class, 'index']);
        Route::get('/settings/payment-methods', [App\Http\Controllers\Owner\SettingsController::class, 'paymentMethods']);
        Route::put('/settings/payment-methods/{method}/toggle', [App\Http\Controllers\Owner\SettingsController::class, 'togglePaymentMethod']);
        Route::put('/settings/{key}', [App\Http\Controllers\Owner\SettingsController::class, 'update']);
    });
    
    /*
    |--------------------------------------------------------------------------
    | ADMIN ONLY ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        
        // ==================== DASHBOARD & PROFILE ====================
        Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'index']);
        Route::get('/profile', [App\Http\Controllers\Admin\ProfileController::class, 'show']);
        Route::put('/profile', [App\Http\Controllers\Admin\ProfileController::class, 'update']);

        // ==================== SETTINGS (PAYMENT METHODS) ====================
        Route::get('/settings', [App\Http\Controllers\Admin\SettingsController::class, 'index']);
        Route::get('/settings/payment-methods', [App\Http\Controllers\Admin\SettingsController::class, 'paymentMethods']);
        Route::put('/settings/payment-methods/{method}/toggle', [App\Http\Controllers\Admin\SettingsController::class, 'togglePaymentMethod']);
        Route::put('/settings/{key}', [App\Http\Controllers\Admin\SettingsController::class, 'update']);

        // ==================== CASHIER MANAGEMENT ====================
        Route::apiResource('cashiers', App\Http\Controllers\Admin\CashierManagementController::class);
        Route::patch('/cashiers/{id}/toggle-active', [App\Http\Controllers\Admin\CashierManagementController::class, 'toggleActive']);
        
        // ==================== PRODUCTS MANAGEMENT ====================
        Route::apiResource('products', App\Http\Controllers\Admin\ProductController::class);
        
        // ==================== STOCK MANAGEMENT ====================
        Route::post('/stocks/add', [App\Http\Controllers\Admin\StockController::class, 'addStock']);
        Route::get('/stocks', [App\Http\Controllers\Admin\StockController::class, 'index']);
        Route::get('/stocks/history', [App\Http\Controllers\Admin\StockController::class, 'history']);
        
        // ==================== SUPPLIERS ====================
        Route::apiResource('suppliers', App\Http\Controllers\Admin\SupplierController::class);
        
        // ==================== BAD PRODUCTS ====================
        Route::apiResource('bad-products', App\Http\Controllers\Admin\BadProductController::class);
        Route::post('/bad-products/preview-calculation', [App\Http\Controllers\Admin\BadProductController::class, 'previewCalculation']);
        Route::post('/bad-products/{id}/compensate-cash', [App\Http\Controllers\Admin\BadProductController::class, 'compensateCash']);
        Route::get('/bad-products/supplier-comparison', [App\Http\Controllers\Admin\BadProductController::class, 'getSupplierComparison']);
        Route::get('/bad-products/suppliers/list', [App\Http\Controllers\Admin\BadProductController::class, 'getSuppliersWithBadProducts']);
        Route::get('/bad-products/supplier/{supplierId}', [App\Http\Controllers\Admin\BadProductController::class, 'getBySupplier']);
        Route::get('/bad-products/supplier/{supplierId}/export-pdf', [App\Http\Controllers\Admin\BadProductController::class, 'exportPdfBySupplier']);
        
        // ==================== PAYABLES (HUTANG SUPPLIER) ====================
        Route::get('/payables', [App\Http\Controllers\Admin\PayableController::class, 'index']);
        Route::post('/payables', [App\Http\Controllers\Admin\PayableController::class, 'store']);
        Route::post('/payables/{id}/pay', [App\Http\Controllers\Admin\PayableController::class, 'pay']);
        
        // ==================== CUSTOMERS (KELOLA PELANGGAN) ====================
        // Specific Routes (Harus di atas wildcard {id})
        Route::get('/customers/calon-member', [App\Http\Controllers\Admin\CustomerController::class, 'calonMember']);
        Route::get('/customers/member', [App\Http\Controllers\Admin\CustomerController::class, 'member']);
        Route::get('/customers/with-receivables', [App\Http\Controllers\Admin\CustomerController::class, 'withReceivables']);
        Route::get('/customers/search', [App\Http\Controllers\Admin\CustomerController::class, 'search']);
        Route::get('/customers/{id}/merge-candidates', [App\Http\Controllers\Admin\CustomerController::class, 'getMergeCandidates']);
        Route::post('/customers/{id}/merge', [App\Http\Controllers\Admin\CustomerController::class, 'merge']);

        // General CRUD & Status Actions
        Route::get('/customers', [App\Http\Controllers\Admin\CustomerController::class, 'index']);
        Route::post('/customers', [App\Http\Controllers\Admin\CustomerController::class, 'store']);
        Route::get('/customers/{id}', [App\Http\Controllers\Admin\CustomerController::class, 'show']);
        Route::put('/customers/{id}', [App\Http\Controllers\Admin\CustomerController::class, 'update']);
        Route::delete('/customers/{id}', [App\Http\Controllers\Admin\CustomerController::class, 'destroy']);
        Route::post('/customers/{id}/approve-member', [App\Http\Controllers\Admin\CustomerController::class, 'approveMember']);
        Route::post('/customers/{id}/reject-member', [App\Http\Controllers\Admin\CustomerController::class, 'rejectMember']);
        Route::post('/customers/{id}/deactivate-member', [App\Http\Controllers\Admin\CustomerController::class, 'deactivateMember']);

        // ==================== RECEIVABLES (PIUTANG CUSTOMER) ====================
        // Daftar piutang
        Route::get('/receivables', [App\Http\Controllers\Admin\ReceivableController::class, 'index']);
        
        // Alert piutang (jatuh tempo & overdue) - harus sebelum {id}
        Route::get('/receivables/alert', [App\Http\Controllers\Admin\ReceivableController::class, 'getAlertReceivables']);
        
        // Ringkasan piutang untuk dashboard - harus sebelum {id}
        Route::get('/receivables/summary', [App\Http\Controllers\Admin\ReceivableController::class, 'getSummary']);
        
        // Detail piutang
        Route::get('/receivables/{id}', [App\Http\Controllers\Admin\ReceivableController::class, 'show']);
        
        // Proses pembayaran piutang via CASH
        Route::post('/receivables/{id}/pay', [App\Http\Controllers\Admin\ReceivableController::class, 'pay']);
        
        // Pelunasan cicilan ke-2 (CASH atau QRIS)
        Route::post('/receivables/{id}/pay-final', [App\Http\Controllers\Admin\ReceivableController::class, 'payFinal']);
        
        // Kirim reminder via WhatsApp
        Route::post('/receivables/{id}/reminder', [App\Http\Controllers\Admin\ReceivableController::class, 'sendReminder']);
        
        // Kirim reminder massal
        Route::post('/receivables/send-bulk-reminders', [App\Http\Controllers\Admin\ReceivableController::class, 'sendBulkReminders']);
        
        // Generate QRIS untuk pelunasan piutang
        Route::post('/receivables/{id}/generate-qris', [App\Http\Controllers\Admin\ReceivableController::class, 'generateQris']);
        
        // ==================== TRANSACTIONS ====================
        Route::get('/transactions', [App\Http\Controllers\Admin\TransactionController::class, 'index']);
        Route::get('/transactions/{id}', [App\Http\Controllers\Admin\TransactionController::class, 'show']);
        // Batalkan/Retur transaksi
        Route::post('/transactions/{id}/cancel', [App\Http\Controllers\Admin\TransactionController::class, 'cancel']);

        // ==================== REPORTS ====================
        Route::get('/reports/sales', [App\Http\Controllers\Admin\ReportController::class, 'salesReport']);
        Route::get('/reports/sales/pdf', [App\Http\Controllers\Admin\ReportController::class, 'exportSalesPDF']);
        Route::get('/reports/sales/excel', [App\Http\Controllers\Admin\ReportController::class, 'exportSalesExcel']);
        Route::get('/reports/stocks', [App\Http\Controllers\Admin\ReportController::class, 'stockReport']);
        Route::get('/reports/stocks/pdf', [App\Http\Controllers\Admin\ReportController::class, 'exportStockPDF']);
        Route::get('/reports/stocks/excel', [App\Http\Controllers\Admin\ReportController::class, 'exportStockExcel']);
        Route::get('/reports/bad-products', [App\Http\Controllers\Admin\ReportController::class, 'badProductReport']);
        Route::get('/reports/bad-products/pdf', [App\Http\Controllers\Admin\ReportController::class, 'exportBadProductPDF']);
        Route::get('/reports/bad-products/excel', [App\Http\Controllers\Admin\ReportController::class, 'exportBadProductExcel']);
        Route::get('/reports/profit', [App\Http\Controllers\Admin\ReportController::class, 'profitReport']);
        Route::get('/reports/profit/pdf', [App\Http\Controllers\Admin\ReportController::class, 'exportProfitPDF']);
        Route::get('/reports/profit/excel', [App\Http\Controllers\Admin\ReportController::class, 'exportProfitExcel']);
        Route::get('/reports/receivables', [App\Http\Controllers\Admin\ReportController::class, 'receivableReport']);
        Route::get('/reports/receivables/pdf', [App\Http\Controllers\Admin\ReportController::class, 'exportReceivablePDF']);
        Route::get('/reports/receivables/excel', [App\Http\Controllers\Admin\ReportController::class, 'exportReceivableExcel']);

        // ==================== FCM TOKENS & NOTIFICATIONS ====================
        Route::post('/fcm-token', [App\Http\Controllers\Admin\FcmTokenController::class, 'store']);
        Route::delete('/fcm-token', [App\Http\Controllers\Admin\FcmTokenController::class, 'destroy']);
        Route::get('/notifications', [App\Http\Controllers\Admin\AdminNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [App\Http\Controllers\Admin\AdminNotificationController::class, 'unreadCount']);
        Route::patch('/notifications/read-all', [App\Http\Controllers\Admin\AdminNotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{id}/read', [App\Http\Controllers\Admin\AdminNotificationController::class, 'markAsRead']);
        Route::delete('/notifications/{id}', [App\Http\Controllers\Admin\AdminNotificationController::class, 'destroy']);
    });
    
    /*
    |--------------------------------------------------------------------------
    | CASHIER ONLY ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware(['role:cashier'])->prefix('cashier')->group(function () {

        // ==================== DASHBOARD KASIR ====================
        Route::get('/dashboard', [App\Http\Controllers\Cashier\DashboardController::class, 'index']);

        // ==================== CUSTOMERS (READ ONLY) ====================
        // List pelanggan untuk dropdown saat metode kredit dipilih
        Route::get('/customers', [App\Http\Controllers\Cashier\CustomerController::class, 'index']);

        // ==================== PROFILE ====================
        Route::get('/profile', [App\Http\Controllers\Cashier\ProfileController::class, 'show']);
        Route::put('/profile', [App\Http\Controllers\Cashier\ProfileController::class, 'update']);

        // ==================== SETTINGS (READ ONLY) ====================
        Route::get('/settings/payment-methods', [App\Http\Controllers\Admin\SettingsController::class, 'paymentMethods']);

        // ==================== PRODUCTS (READ ONLY) ====================
        Route::get('/products', [App\Http\Controllers\Cashier\ProductController::class, 'index']);
        Route::get('/products/{id}', [App\Http\Controllers\Cashier\ProductController::class, 'show']);
        
        // ==================== TRANSACTIONS ====================
        // Transaksi baru (CASH, QRIS, TRANSFER, RECEIVABLE)
        Route::post('/transactions', [App\Http\Controllers\Cashier\TransactionController::class, 'store']);
        
        // Riwayat transaksi kasir
        Route::get('/transactions/history', [App\Http\Controllers\Cashier\TransactionController::class, 'history']);
        
        // Detail struk transaksi
        Route::get('/transactions/{id}/struk', [App\Http\Controllers\Cashier\TransactionController::class, 'getStruk']);

        // Status polling untuk Midtrans QRIS
        Route::get('/transactions/{id}/status', [App\Http\Controllers\Cashier\TransactionController::class, 'getPaymentStatus']);

        // Cek status Midtrans langsung via API
        Route::get('/transactions/{id}/check-midtrans-status', [App\Http\Controllers\Cashier\TransactionController::class, 'checkMidtransStatus']);

        // Konfirmasi pembayaran QRIS (manual oleh kasir)
        Route::patch('/transactions/{id}/confirm-payment', [App\Http\Controllers\Cashier\TransactionController::class, 'confirmPayment']);
        
        // ==================== RECEIVABLES (PIUTANG) ====================
        Route::get('/receivables', [App\Http\Controllers\Cashier\ReceivableController::class, 'index']);
        Route::post('/receivables/{id}/pay', [App\Http\Controllers\Cashier\ReceivableController::class, 'payReceivable']);
        
        // ==================== PRINT STRUK ====================
        Route::get('/print/{transaction_id}', [App\Http\Controllers\Cashier\PrintController::class, 'printStruk']);
    });
});