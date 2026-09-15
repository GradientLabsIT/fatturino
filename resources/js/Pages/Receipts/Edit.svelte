<script>
    import Authenticated from '$layouts/Authenticated.svelte'
    import ReceiptForm from './ReceiptForm.svelte'

    let { receipt, initial = {}, formData = {} } = $props()
</script>

<Authenticated>
    <ReceiptForm {receipt} {initial} {formData} />
</Authenticated>
