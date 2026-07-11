<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payable;
use App\Traits\ApiResponseTrait;
use App\Services\SerenityLoggerService;
use App\Helpers\CloudinaryHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayableController extends Controller
{
    use ApiResponseTrait;

    protected $logger;

    public function __construct(SerenityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $status = $request->input('status');
            
            $query = Payable::with('supplier');
            
            if ($status && in_array($status, ['unpaid', 'partial', 'paid'])) {
                $query->where('status', $status);
            }
            
            $payables = $query->orderBy('due_date', 'asc')->paginate($perPage);
            
            $summary = [
                'total_unpaid' => (float) Payable::where('status', '!=', 'paid')->sum('remaining_debt'),
                'overdue_count' => Payable::where('due_date', '<', now())
                    ->where('status', '!=', 'paid')
                    ->count(),
                'total_paid' => (float) Payable::where('status', 'paid')->sum('total_debt'),
            ];
            
            // Format response agar konsisten dengan Flutter
            return $this->success([
                'payables' => $payables,
                'summary' => $summary
            ], 'Daftar hutang berhasil dimuat', 200);
            
        } catch (\Exception $e) {
            $this->logger->error('Get payables error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memuat daftar hutang', null, 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'total_debt' => 'required|numeric|min:1',
                'due_date' => 'required|date|after:today',
            ]);
            
            $payable = Payable::create([
                'supplier_id' => $request->supplier_id,
                'total_debt' => $request->total_debt,
                'paid_amount' => 0,
                'remaining_debt' => $request->total_debt,
                'due_date' => $request->due_date,
                'status' => 'unpaid',
            ]);
            
            $this->logger->info('Hutang baru dicatat oleh Admin', [
                'payable_id' => $payable->id,
                'supplier_id' => $request->supplier_id,
                'total_debt' => $request->total_debt,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success($payable, 'Hutang berhasil dicatat', 201);
            
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Data hutang tidak valid');
        } catch (\Exception $e) {
            $this->logger->error('Create payable error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat mencatat hutang', null, 500);
        }
    }

    public function pay($id, Request $request)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'payment_amount' => 'required|numeric|min:1',
                'bukti_pembayaran' => 'required|image|mimes:jpeg,png,jpg|max:2048',
                'notes' => 'nullable|string|max:500',
            ]);
            
            $payable = Payable::with('supplier')->where('id', $id)->lockForUpdate()->firstOrFail();
            
            if ($payable->status === 'paid') {
                DB::rollBack();
                return $this->error('Hutang ini sudah lunas', null, 400);
            }
            
            if ($request->payment_amount > $payable->remaining_debt) {
                DB::rollBack();
                return $this->error('Jumlah pembayaran melebihi sisa hutang', null, 400);
            }
            
            $newPaidAmount = $payable->paid_amount + $request->payment_amount;
            $newRemainingDebt = $payable->remaining_debt - $request->payment_amount;
            
            $payable->paid_amount = $newPaidAmount;
            $payable->remaining_debt = $newRemainingDebt;
            $payable->status = $newRemainingDebt <= 0 ? 'paid' : 'partial';

            $oldBuktiPath = $payable->bukti_pembayaran;

            if ($request->hasFile('bukti_pembayaran')) {
                $path = CloudinaryHelper::upload($request->file('bukti_pembayaran'), 'payables');
                $payable->bukti_pembayaran = $path;
            }

            $dateStr = now()->format('d/m/Y');
            $amountStr = number_format($request->payment_amount, 0, ',', '.');
            
            $newNoteEntry = "{$dateStr}: Dibayar Rp {$amountStr}";
            if ($request->filled('notes')) {
                $newNoteEntry .= " - Catatan: " . $request->notes;
            }
            
            if (!empty($payable->notes)) {
                $payable->notes = $payable->notes . "\n" . $newNoteEntry;
            } else {
                $payable->notes = $newNoteEntry;
            }

            $payable->save();
            
            DB::commit();
            
            if ($oldBuktiPath && $oldBuktiPath !== $payable->bukti_pembayaran) {
                CloudinaryHelper::delete($oldBuktiPath);
            }
            
            $this->logger->info('Pembayaran hutang berhasil', [
                'payable_id' => $payable->id,
                'supplier_name' => $payable->supplier->name,
                'payment_amount' => $request->payment_amount,
                'admin_id' => $request->user()->id
            ]);
            
            return $this->success([
                'payable' => $payable,
                'remaining_debt' => $newRemainingDebt,
                'status' => $payable->status
            ], 'Pembayaran hutang berhasil', 200);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors(), 'Data pembayaran tidak valid');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->error('Pay payable error: ' . $e->getMessage());
            return $this->error('Terjadi kesalahan saat memproses pembayaran', null, 500);
        }
    }
}