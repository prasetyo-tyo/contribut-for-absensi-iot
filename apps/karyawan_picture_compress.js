(function () {
  const MAX_WIDTH = 1280;
  const MAX_HEIGHT = 1280;
  const JPEG_QUALITY = 0.78;
  const CROP_WIDTH = 800;
  const CROP_HEIGHT = 1200;
  const INPUT_NAMES = ['picture_file'];

  function formatBytes(bytes) {
    if (!bytes) return '0 KB';
    if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(2) + ' MB';
    return Math.ceil(bytes / 1024) + ' KB';
  }

  function setStatus(input, message, isError) {
    let status = input.parentNode.querySelector('.picture-compress-status');
    if (!status) {
      status = document.createElement('small');
      status.className = 'picture-compress-status form-text';
      input.parentNode.appendChild(status);
    }
    status.classList.toggle('text-danger', Boolean(isError));
    status.classList.toggle('text-muted', !isError);
    status.textContent = message;
  }

  function canvasToBlob(canvas, type, quality) {
    return new Promise(function (resolve) {
      canvas.toBlob(resolve, type, quality);
    });
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('Gambar tidak bisa dibaca browser.'));
      };
      img.src = url;
    });
  }

  function setInputFile(input, file) {
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    input.files = dataTransfer.files;
  }

  async function openCropModal(file) {
    const img = await loadImage(file);
    const frameWidth = Math.min(280, Math.max(240, window.innerWidth - 80));
    const frameHeight = Math.round(frameWidth * 1.5);
    let zoom = 1;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;

    function getDrawMetrics(targetWidth, targetHeight) {
      const scale = Math.max(targetWidth / img.naturalWidth, targetHeight / img.naturalHeight) * zoom;
      const drawWidth = img.naturalWidth * scale;
      const drawHeight = img.naturalHeight * scale;

      return { scale, drawWidth, drawHeight };
    }

    function clampOffset(targetWidth, targetHeight) {
      const metrics = getDrawMetrics(targetWidth, targetHeight);
      const maxOffsetX = Math.max(0, (metrics.drawWidth - targetWidth) / 2);
      const maxOffsetY = Math.max(0, (metrics.drawHeight - targetHeight) / 2);

      offsetX = Math.min(maxOffsetX, Math.max(-maxOffsetX, offsetX));
      offsetY = Math.min(maxOffsetY, Math.max(-maxOffsetY, offsetY));
    }

    return new Promise(function (resolve) {
      const overlay = document.createElement('div');
      overlay.style.cssText = 'position:fixed;z-index:10000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.82);display:flex;align-items:center;justify-content:center;padding:14px;';
      overlay.innerHTML = [
        '<div style="background:#fff;border-radius:12px;max-width:460px;width:100%;padding:16px;box-shadow:0 16px 40px rgba(0,0,0,.35);">',
        '<h5 style="margin:0 0 6px;">Crop Foto Karyawan 4x6</h5>',
        '<small class="text-muted d-block mb-2">Geser foto di area crop, lalu atur zoom sampai wajah pas.</small>',
        '<canvas data-crop-canvas style="display:block;margin:0 auto;background:#777;border-radius:6px;touch-action:none;cursor:move;border:3px solid #1f6f43;"></canvas>',
        '<label style="display:block;margin-top:10px;margin-bottom:4px;font-size:13px;font-weight:bold;">Zoom</label>',
        '<input data-crop-zoom type="range" min="1" max="3" step="0.01" value="1" style="width:100%;">',
        '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap;">',
        '<button type="button" class="btn btn-secondary btn-sm" data-crop-cancel>Batal</button>',
        '<button type="button" class="btn btn-primary btn-sm" data-crop-apply>Gunakan Foto</button>',
        '</div>',
        '</div>'
      ].join('');

      const canvas = overlay.querySelector('[data-crop-canvas]');
      const range = overlay.querySelector('[data-crop-zoom]');
      const cancelButton = overlay.querySelector('[data-crop-cancel]');
      const applyButton = overlay.querySelector('[data-crop-apply]');
      canvas.width = frameWidth;
      canvas.height = frameHeight;
      const ctx = canvas.getContext('2d');

      function drawPreview() {
        clampOffset(canvas.width, canvas.height);
        const metrics = getDrawMetrics(canvas.width, canvas.height);
        const drawWidth = metrics.drawWidth;
        const drawHeight = metrics.drawHeight;
        const x = (canvas.width - drawWidth) / 2 + offsetX;
        const y = (canvas.height - drawHeight) / 2 + offsetY;

        ctx.fillStyle = '#777';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, x, y, drawWidth, drawHeight);
        ctx.strokeStyle = 'rgba(255,255,255,.85)';
        ctx.lineWidth = 1;
        ctx.strokeRect(8, 8, canvas.width - 16, canvas.height - 16);
      }

      function pointerPosition(event) {
        const point = event.touches && event.touches[0] ? event.touches[0] : event;
        return { x: point.clientX, y: point.clientY };
      }

      function startDrag(event) {
        event.preventDefault();
        dragging = true;
        const pos = pointerPosition(event);
        lastX = pos.x;
        lastY = pos.y;
      }

      function moveDrag(event) {
        if (!dragging) return;
        event.preventDefault();
        const pos = pointerPosition(event);
        offsetX += pos.x - lastX;
        offsetY += pos.y - lastY;
        lastX = pos.x;
        lastY = pos.y;
        drawPreview();
      }

      function endDrag() {
        dragging = false;
      }

      canvas.addEventListener('mousedown', startDrag);
      window.addEventListener('mousemove', moveDrag);
      window.addEventListener('mouseup', endDrag);
      canvas.addEventListener('touchstart', startDrag, { passive: false });
      window.addEventListener('touchmove', moveDrag, { passive: false });
      window.addEventListener('touchend', endDrag);
      range.addEventListener('input', function () {
        zoom = parseFloat(range.value) || 1;
        clampOffset(canvas.width, canvas.height);
        drawPreview();
      });

      function cleanup(result) {
        window.removeEventListener('mousemove', moveDrag);
        window.removeEventListener('mouseup', endDrag);
        window.removeEventListener('touchmove', moveDrag);
        window.removeEventListener('touchend', endDrag);
        overlay.remove();
        resolve(result);
      }

      cancelButton.addEventListener('click', function () {
        cleanup(null);
      });

      applyButton.addEventListener('click', async function () {
        const out = document.createElement('canvas');
        out.width = CROP_WIDTH;
        out.height = CROP_HEIGHT;
        const outCtx = out.getContext('2d');
        outCtx.fillStyle = '#fff';
        outCtx.fillRect(0, 0, out.width, out.height);

        const previewRatio = out.width / canvas.width;
        clampOffset(canvas.width, canvas.height);
        const finalScale = Math.max(out.width / img.naturalWidth, out.height / img.naturalHeight) * zoom;
        const drawWidth = img.naturalWidth * finalScale;
        const drawHeight = img.naturalHeight * finalScale;
        const x = (out.width - drawWidth) / 2 + (offsetX * previewRatio);
        const y = (out.height - drawHeight) / 2 + (offsetY * previewRatio);
        outCtx.drawImage(img, x, y, drawWidth, drawHeight);

        const blob = await canvasToBlob(out, 'image/jpeg', 0.84);
        if (!blob) {
          cleanup(null);
          return;
        }

        const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto-karyawan';
        cleanup(new File([blob], baseName + '-4x6.jpg', {
          type: 'image/jpeg',
          lastModified: Date.now()
        }));
      });

      document.body.appendChild(overlay);
      drawPreview();
    });
  }

  async function compressImageFile(file) {
    if (!file || !file.type || !file.type.match(/^image\//)) return file;

    // Browser biasanya tidak bisa decode HEIC langsung. Biarkan server menolak dengan pesan format.
    if (file.type === 'image/heic' || file.type === 'image/heif') return file;

    const img = await loadImage(file);
    let width = img.naturalWidth || img.width;
    let height = img.naturalHeight || img.height;
    const scale = Math.min(MAX_WIDTH / width, MAX_HEIGHT / height, 1);
    width = Math.round(width * scale);
    height = Math.round(height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, width, height);

    const blob = await canvasToBlob(canvas, 'image/jpeg', JPEG_QUALITY);
    if (!blob) return file;

    // Kalau hasil kompresi malah lebih besar, tetap pakai file asli.
    if (blob.size >= file.size) return file;

    const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto-karyawan';
    return new File([blob], baseName + '.jpg', {
      type: 'image/jpeg',
      lastModified: Date.now(),
    });
  }

  async function handleInputChange(event) {
    const input = event.target;
    const file = input.files && input.files[0];
    if (!file) return;

    if (!window.File || !window.DataTransfer || !document.createElement('canvas').toBlob) {
      setStatus(input, 'Browser tidak mendukung crop/kompres otomatis. File dikirim apa adanya.', true);
      return;
    }

    if (input.dataset.cropReady !== '1' && file.type && file.type.match(/^image\//) && file.type !== 'image/heic' && file.type !== 'image/heif') {
      setStatus(input, 'Silakan crop foto 4x6 sebelum upload.', false);
      try {
        const cropped = await openCropModal(file);
        if (!cropped) {
          input.value = '';
          setStatus(input, 'Upload foto dibatalkan.', true);
          return;
        }
        input.dataset.cropReady = '1';
        setInputFile(input, cropped);
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (error) {
        setStatus(input, 'Crop foto gagal. Coba pilih foto lain.', true);
      }
      return;
    }

    input.dataset.cropReady = '';
    setStatus(input, 'Mengompres foto...', false);

    try {
      const originalSize = file.size;
      const compressed = await compressImageFile(file);
      setInputFile(input, compressed);

      if (compressed.size < originalSize) {
        setStatus(input, 'Foto 4x6 dikompres: ' + formatBytes(originalSize) + ' -> ' + formatBytes(compressed.size), false);
      } else {
        setStatus(input, 'Foto 4x6 siap upload: ' + formatBytes(originalSize), false);
      }
    } catch (error) {
      setStatus(input, 'Kompres otomatis gagal. File dikirim asli.', true);
    }
  }

  document.addEventListener('change', function (event) {
    if (INPUT_NAMES.indexOf(event.target.name) !== -1) {
      handleInputChange(event);
    }
  });

  function createCameraModal(input) {
    let stream = null;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.78);align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = [
      '<div style="background:#fff;border-radius:10px;max-width:520px;width:100%;padding:16px;">',
      '<h5 style="margin-bottom:12px;">Ambil Foto Kamera</h5>',
      '<video autoplay playsinline style="width:100%;background:#111;border-radius:8px;"></video>',
      '<canvas style="display:none;"></canvas>',
      '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap;">',
      '<button type="button" class="btn btn-secondary btn-sm" data-camera-close>Batal</button>',
      '<button type="button" class="btn btn-primary btn-sm" data-camera-capture>Ambil Foto</button>',
      '</div>',
      '<small class="text-muted d-block mt-2">Kamera hanya bisa dibuka jika website memakai HTTPS atau localhost.</small>',
      '</div>'
    ].join('');

    const video = overlay.querySelector('video');
    const canvas = overlay.querySelector('canvas');
    const closeButton = overlay.querySelector('[data-camera-close]');
    const captureButton = overlay.querySelector('[data-camera-capture]');

    async function stopCamera() {
      if (stream) {
        stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
      }
      video.srcObject = null;
      overlay.style.display = 'none';
    }

    async function openCamera() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setStatus(input, 'Browser ini tidak mendukung kamera langsung. Gunakan upload file.', true);
        return;
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 1280 } },
          audio: false
        });
      } catch (error) {
        try {
          stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        } catch (fallbackError) {
          setStatus(input, 'Kamera tidak bisa dibuka. Pastikan izin kamera aktif dan akses lewat HTTPS.', true);
          return;
        }
      }

      video.srcObject = stream;
      overlay.style.display = 'flex';
    }

    captureButton.addEventListener('click', async function () {
      const width = video.videoWidth || 1280;
      const height = video.videoHeight || 720;
      canvas.width = width;
      canvas.height = height;
      canvas.getContext('2d').drawImage(video, 0, 0, width, height);

      const blob = await canvasToBlob(canvas, 'image/jpeg', 0.82);
      if (!blob) {
        setStatus(input, 'Gagal mengambil foto dari kamera.', true);
        return;
      }

      const file = new File([blob], 'foto-kamera-' + Date.now() + '.jpg', {
        type: 'image/jpeg',
        lastModified: Date.now()
      });
      setInputFile(input, file);
      await stopCamera();
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    closeButton.addEventListener('click', stopCamera);
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) stopCamera();
    });

    document.body.appendChild(overlay);
    return openCamera;
  }

  function attachCameraButtons() {
    document.querySelectorAll('input[type="file"][name="picture_file"]').forEach(function (input) {
      if (input.dataset.cameraButtonAttached === '1') return;
      input.dataset.cameraButtonAttached = '1';

      const openCamera = createCameraModal(input);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-outline-primary btn-sm mb-2';
      button.textContent = 'Ambil Foto Kamera';
      button.addEventListener('click', openCamera);

      input.parentNode.insertBefore(button, input);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachCameraButtons);
  } else {
    attachCameraButtons();
  }

})();
