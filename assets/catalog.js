jQuery(function ($) {
    $(document).on('click', '.faluss-catalog__media-button', function (event) {
        event.preventDefault();

        const container = $(this).closest('.faluss-catalog__media');
        const field = container.find('input[name="preview_attachment_id"]');
        const preview = container.find('.faluss-catalog__preview');
        const frame = wp.media({
            title: 'Image de prévisualisation',
            button: { text: 'Utiliser cette image' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function () {
            const image = frame.state().get('selection').first();
            field.val(image.get('id'));
            preview.attr('src', image.get('url')).prop('hidden', false);
        });

        frame.open();
    });
});
