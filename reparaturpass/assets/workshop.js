(() => {
    'use strict';
    const target = document.querySelector('[data-rp-qr]');
    const print = document.querySelector('[data-rp-print]');
    if (!target || !print || typeof window.qrcode !== 'function') {
        return;
    }

    const code = window.qrcode(0, 'M');
    code.addData(target.dataset.url, 'Byte');
    code.make();

    const scale = 5;
    const margin = 4;
    const modules = code.getModuleCount();
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = (modules + 2 * margin) * scale;
    canvas.setAttribute('aria-hidden', 'true');
    const context = canvas.getContext('2d');
    if (!context) {
        return;
    }
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#000';
    for (let row = 0; row < modules; row++) {
        for (let column = 0; column < modules; column++) {
            if (code.isDark(row, column)) {
                context.fillRect((column + margin) * scale, (row + margin) * scale, scale, scale);
            }
        }
    }
    target.append(canvas);
    print.hidden = false;
    print.addEventListener('click', () => window.print());
})();
