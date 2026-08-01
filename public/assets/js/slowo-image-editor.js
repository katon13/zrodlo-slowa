(function (window) {
  'use strict';

  class SlowoImageEditor {
    constructor(options) {
      this.options = Object.assign({
        width: 1600,
        height: 900,
        maxFileSize: 5 * 1024 * 1024,
        outputType: 'image/webp',
        outputQuality: 0.88,
        minZoom: 1,
        maxZoom: 5,
        initialZoom: 1,
        invalidTypeMessage: 'Plik musi być obrazem JPG, PNG albo WEBP.',
        fileTooLargeMessage: 'Plik jest zbyt duży.',
        onReady: null,
        onClear: null
      }, options || {});

      this.input = this.options.input;
      this.dropzone = this.options.dropzone;
      this.canvas = this.options.canvas;
      this.zoom = this.options.zoom;
      this.hiddenData = this.options.hiddenData || null;
      this.hiddenName = this.options.hiddenName || null;
      this.fileNameNode = this.options.fileNameNode || null;
      this.statusNode = this.options.statusNode || null;
      this.previewNode = this.options.previewNode || null;

      if (!this.input || !this.dropzone || !this.canvas || !this.zoom) {
        return;
      }

      this.ctx = this.canvas.getContext('2d');
      this.image = new Image();
      this.imageName = '';
      this.baseScale = 1;
      this.imageX = 0;
      this.imageY = 0;
      this.dragging = false;
      this.lastX = 0;
      this.lastY = 0;
      this.ready = false;

      this.canvas.width = this.options.width;
      this.canvas.height = this.options.height;
      this.zoom.min = String(this.options.minZoom);
      this.zoom.max = String(this.options.maxZoom);
      this.zoom.step = this.zoom.step || '0.01';
      this.zoom.value = String(this.options.initialZoom);

      this.bind();
      this.drawEmpty();
    }

    bind() {
      this.input.addEventListener('change', (event) => {
        const file = event.target.files && event.target.files[0];
        if (file) {
          this.loadFile(file);
        }
      });

      ['dragenter', 'dragover', 'dragleave', 'drop'].forEach((eventName) => {
        this.dropzone.addEventListener(eventName, (event) => {
          event.preventDefault();
          event.stopPropagation();
        }, false);
      });

      ['dragenter', 'dragover'].forEach((eventName) => {
        this.dropzone.addEventListener(eventName, () => {
          this.dropzone.classList.add('dragover');
        }, false);
      });

      ['dragleave', 'drop'].forEach((eventName) => {
        this.dropzone.addEventListener(eventName, () => {
          this.dropzone.classList.remove('dragover');
        }, false);
      });

      this.dropzone.addEventListener('drop', (event) => {
        const file = event.dataTransfer.files && event.dataTransfer.files[0];
        if (file) {
          this.loadFile(file);
        }
      });

      this.zoom.addEventListener('input', () => this.draw());

      this.canvas.addEventListener('mousedown', (event) => this.startDrag(event.clientX, event.clientY));
      window.addEventListener('mousemove', (event) => this.moveDrag(event.clientX, event.clientY));
      window.addEventListener('mouseup', () => this.stopDrag());

      this.canvas.addEventListener('touchstart', (event) => {
        if (event.touches.length !== 1) return;
        event.preventDefault();
        this.startDrag(event.touches[0].clientX, event.touches[0].clientY);
      }, { passive: false });

      this.canvas.addEventListener('touchmove', (event) => {
        if (event.touches.length !== 1) return;
        event.preventDefault();
        this.moveDrag(event.touches[0].clientX, event.touches[0].clientY);
      }, { passive: false });

      this.canvas.addEventListener('touchend', () => this.stopDrag());
    }

    loadFile(file) {
      if (!file.type || !file.type.startsWith('image/')) {
        this.setStatus(this.options.invalidTypeMessage, 'error');
        this.input.value = '';
        return;
      }

      const allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
      if (!allowed.includes(file.type)) {
        this.setStatus(this.options.invalidTypeMessage, 'error');
        this.input.value = '';
        return;
      }

      if (file.size > this.options.maxFileSize) {
        this.setStatus(this.options.fileTooLargeMessage, 'error');
        this.input.value = '';
        return;
      }

      this.imageName = file.name || 'obraz.webp';
      if (this.fileNameNode) {
        this.fileNameNode.textContent = this.imageName;
      }
      if (this.hiddenName) {
        this.hiddenName.value = this.imageName;
      }
      this.setStatus('', '');

      const reader = new FileReader();
      reader.onload = (event) => {
        this.image.onload = () => {
          this.prepareImage();
          this.ready = true;
          this.dropzone.classList.add('has-image');
          if (this.previewNode) {
            this.previewNode.style.display = '';
          }
          this.draw();
          if (typeof this.options.onReady === 'function') {
            this.options.onReady(this);
          }
        };
        this.image.src = event.target.result;
      };
      reader.readAsDataURL(file);
    }

    prepareImage() {
      const scaleW = this.canvas.width / this.image.width;
      const scaleH = this.canvas.height / this.image.height;
      this.baseScale = Math.max(scaleW, scaleH);
      this.zoom.value = String(this.options.initialZoom);
      this.imageX = (this.canvas.width - this.image.width * this.baseScale) / 2;
      this.imageY = (this.canvas.height - this.image.height * this.baseScale) / 2;
    }

    drawEmpty() {
      this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
      this.ctx.fillStyle = '#f4f1ef';
      this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
    }

    draw() {
      if (!this.ready || !this.image || !this.image.width) {
        this.drawEmpty();
        return;
      }

      this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
      this.ctx.fillStyle = '#f4f1ef';
      this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

      const currentScale = this.baseScale * parseFloat(this.zoom.value || '1');
      this.ctx.drawImage(
        this.image,
        this.imageX,
        this.imageY,
        this.image.width * currentScale,
        this.image.height * currentScale
      );

      this.writeOutput();
    }

    writeOutput() {
      if (!this.hiddenData || !this.ready) return;
      this.hiddenData.value = this.canvas.toDataURL(this.options.outputType, this.options.outputQuality);
    }

    getDataUrl() {
      if (!this.ready) return '';
      return this.canvas.toDataURL(this.options.outputType, this.options.outputQuality);
    }

    startDrag(x, y) {
      if (!this.ready) return;
      this.dragging = true;
      this.lastX = x;
      this.lastY = y;
      this.canvas.classList.add('is-dragging');
    }

    moveDrag(x, y) {
      if (!this.dragging || !this.ready) return;
      const rect = this.canvas.getBoundingClientRect();
      const factorX = this.canvas.width / Math.max(1, rect.width);
      const factorY = this.canvas.height / Math.max(1, rect.height);
      this.imageX += (x - this.lastX) * factorX;
      this.imageY += (y - this.lastY) * factorY;
      this.lastX = x;
      this.lastY = y;
      this.draw();
    }

    stopDrag() {
      this.dragging = false;
      this.canvas.classList.remove('is-dragging');
    }

    clear() {
      this.ready = false;
      this.image = new Image();
      this.imageName = '';
      this.input.value = '';
      this.dropzone.classList.remove('has-image');
      this.dropzone.classList.remove('dragover');
      this.zoom.value = String(this.options.initialZoom);
      if (this.hiddenData) this.hiddenData.value = '';
      if (this.hiddenName) this.hiddenName.value = '';
      if (this.fileNameNode) this.fileNameNode.textContent = '';
      this.drawEmpty();
      if (typeof this.options.onClear === 'function') {
        this.options.onClear(this);
      }
    }

    setStatus(text, type) {
      if (!this.statusNode) return;
      this.statusNode.textContent = text || '';
      this.statusNode.className = 'zs-upload-status' + (type ? ' ' + type : '');
    }
  }

  window.SlowoImageEditor = SlowoImageEditor;
})(window);
