<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Simpan seluruh daftar pemetaan pemasok untuk satu produk sekaligus
 * (satu form berisi banyak baris pivot).
 */
class SyncProductSuppliersRequest extends FormRequest
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
            'suppliers' => ['present', 'array'],

            // Baris tanpa supplier_id = baris kosong yang diabaikan service,
            // jadi tidak divalidasi sebagai wajib.
            'suppliers.*.supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id'),
            ],
            'suppliers.*.last_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'suppliers.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $selected = collect($this->input('suppliers', []))
                ->pluck('supplier_id')
                ->filter()
                ->map(fn ($id): int => (int) $id);

            $duplicates = $selected->duplicates();

            if ($duplicates->isNotEmpty()) {
                $validator->errors()->add(
                    'suppliers',
                    'Pemasok '.implode(', ', $duplicates->unique()->all()).' tercatat lebih dari sekali.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'suppliers.required' => 'Daftar pemasok tidak boleh kosong.',
            'suppliers.array' => 'Format daftar pemasok tidak valid.',
            'suppliers.*.supplier_id.integer' => 'Pemasok yang dipilih tidak valid.',
            'suppliers.*.supplier_id.exists' => 'Ada pemasok yang sudah tidak ada.',
            'suppliers.*.last_purchase_price.numeric' => 'Harga beli harus berupa angka.',
            'suppliers.*.last_purchase_price.min' => 'Harga beli tidak boleh negatif.',
            'suppliers.*.lead_time_days.integer' => 'Lead time harus bilangan bulat.',
            'suppliers.*.lead_time_days.min' => 'Lead time tidak boleh negatif.',
            'suppliers.*.lead_time_days.max' => 'Lead time maksimal 365 hari.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'suppliers' => 'daftar pemasok',
            'suppliers.*.last_purchase_price' => 'harga beli terakhir',
            'suppliers.*.lead_time_days' => 'lead time',
        ];
    }
}
