<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,transfer,qris,midtrans_qris,receivable',
        ];

        if ($this->payment_method !== 'receivable') {
            $rules['paid_amount'] = 'required|numeric|min:0';
        }

        // customer_id WAJIB untuk metode receivable (piutang)
        if ($this->payment_method === 'receivable') {
            $rules['customer_id'] = 'required|integer|exists:customers,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Keranjang belanja tidak boleh kosong',
            'items.min' => 'Minimal 1 produk dalam transaksi',
            'items.*.product_id.required' => 'Produk wajib dipilih',
            'items.*.product_id.exists' => 'Produk tidak ditemukan',
            'items.*.quantity.required' => 'Jumlah produk wajib diisi',
            'items.*.quantity.min' => 'Jumlah produk minimal 1',
            'payment_method.required' => 'Metode pembayaran wajib dipilih',
            'payment_method.in' => 'Metode pembayaran tidak valid',
            'paid_amount.required' => 'Jumlah pembayaran wajib diisi',
            'paid_amount.min' => 'Jumlah pembayaran tidak boleh negatif',
            'customer_id.required' => 'Pilih pelanggan terdaftar untuk transaksi kredit',
            'customer_id.integer' => 'ID pelanggan tidak valid',
            'customer_id.exists' => 'Pelanggan tidak ditemukan',
        ];
    }
}