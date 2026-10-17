<div class="space-y-5 rounded-lg border p-6" data-skysepay-invoice="{{ $invoice->id }}">
    <h2 class="text-xl font-semibold">Thanh toán chuyển khoản ngân hàng</h2>
    <p>Quét mã VietQR bằng ứng dụng ngân hàng. Nhập đúng số tiền và nội dung chuyển khoản bên dưới.</p>

    <div class="flex justify-center">
        <img src="{{ $qrUrl }}" alt="Mã VietQR thanh toán SePay" class="h-64 w-64" loading="eager">
    </div>

    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium">Ngân hàng</dt><dd>{{ $bankCode }}</dd></div>
        <div><dt class="font-medium">Chủ tài khoản</dt><dd>{{ $accountName }}</dd></div>
        <div><dt class="font-medium">Số tài khoản</dt><dd>{{ $accountNumber }}</dd></div>
        <div><dt class="font-medium">Số tiền</dt><dd>{{ number_format($amount, 0, ',', '.') }} ₫</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">Nội dung chuyển khoản</dt><dd class="font-mono">{{ $reference }}</dd></div>
    </dl>

    <p class="text-sm">Hóa đơn chỉ được xác nhận sau khi SePay gửi webhook đã xác thực và số tiền khớp chính xác. Không đóng hóa đơn hoặc tạo chuyển khoản khác để thay thế giao dịch đang xử lý.</p>
    <a href="{{ route('invoices.show', $invoice) }}" class="underline">Quay lại hóa đơn để kiểm tra trạng thái</a>
</div>
