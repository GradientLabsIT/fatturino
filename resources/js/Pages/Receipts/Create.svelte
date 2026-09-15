<script>
    import Authenticated from '$layouts/Authenticated.svelte'
    import ReceiptForm from './ReceiptForm.svelte'

    let { formData = {} } = $props()
</script>

<Authenticated>
    <ReceiptForm {formData} />
</Authenticated>
