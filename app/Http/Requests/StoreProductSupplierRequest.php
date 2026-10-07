<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pemetaan harga beli & lead time per pemasok untuk satu produk
 * (PRD Modul 1 — Pemetaan Harga & Lead Time).
 */
class StoreProductSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'last_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pilih pemasok terlebih dahulu.',
            'supplier_id.integer' => 'Pemasok yang dipilih tidak valid.',
            'supplier_id.exists' => 'Pemasok yang dipilih tidak ada.',
            'last_purchase_price.numeric' => 'Harga beli harus berupa angka.',
            'last_purchase_price.min' => 'Harga beli tidak boleh negatif.',
            'lead_time_days.integer' => 'Lead time harus bilangan bulat.',
            'lead_time_days.min' => 'Lead time tidak boleh negatif.',
            'lead_time_days.max' => 'Lead time maksimal 365 hari.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'pemasok',
            'last_purchase_price' => 'harga beli terakhir',
            'lead_time_days' => 'lead time',
        ];
    }
}
