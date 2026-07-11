<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BadProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'damage_reason' => 'required|string',
            'incident_date' => 'required|date',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Produk wajib dipilih',
            'product_id.exists' => 'Produk tidak ditemukan',
            'quantity.required' => 'Jumlah rusak wajib diisi',
            'quantity.min' => 'Jumlah rusak minimal 1',
            'damage_reason.required' => 'Penyebab kerusakan wajib diisi',
            'incident_date.required' => 'Tanggal kejadian wajib diisi',
            'image.image' => 'File harus berupa gambar',
            'image.mimes' => 'Format gambar harus jpeg, png, atau jpg',
            'image.max' => 'Ukuran gambar maksimal 2MB',
        ];
    }
}