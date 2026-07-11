<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'category' => 'required|in:egg,rice',
            'unit' => 'required|string|in:tray,butir,kg,karung',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0|gt:purchase_price',
            'min_stock' => 'required|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules = [
                'name' => 'sometimes|string|max:255',
                'category' => 'sometimes|in:egg,rice',
                'unit' => 'sometimes|string|in:tray,butir,kg,karung',
                'purchase_price' => 'sometimes|numeric|min:0',
                'selling_price' => 'sometimes|numeric|min:0',
                'min_stock' => 'sometimes|integer|min:0',
                'supplier_id' => 'nullable|exists:suppliers,id',
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama produk wajib diisi',
            'category.required' => 'Kategori produk wajib diisi',
            'category.in' => 'Kategori produk harus egg atau rice',
            'unit.required' => 'Satuan produk wajib diisi',
            'purchase_price.required' => 'Harga beli wajib diisi',
            'purchase_price.min' => 'Harga beli tidak boleh negatif',
            'selling_price.required' => 'Harga jual wajib diisi',
            'selling_price.gt' => 'Harga jual harus lebih besar dari harga beli',
            'min_stock.required' => 'Stok minimum wajib diisi',
            'supplier_id.exists' => 'Supplier tidak ditemukan',
        ];
    }
}