
document.addEventListener('DOMContentLoaded', function () {
    const deleteForms = document.querySelectorAll('.delete-form');

    deleteForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            const confirmed = window.confirm(
                'Are you sure you want to delete this product?'
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });
});
