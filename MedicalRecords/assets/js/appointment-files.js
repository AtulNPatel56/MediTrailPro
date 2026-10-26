document.querySelectorAll('.appointment-upload-button input[type="file"]').forEach((input) => {
    input.addEventListener('change', () => {
        const selection = input.parentElement.querySelector('.appointment-file-selection');
        const files = Array.from(input.files || []);
        selection.textContent = files.length === 0
            ? ''
            : files.length === 1
                ? files[0].name
                : `${files.length} files selected`;
    });
});
