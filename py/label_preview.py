#!/usr/bin/env python3
"""
label_preview.py  –  Interactive label designer
Run standalone:  python label_preview.py
Or with data:    python label_preview.py --payload-file payload.json
"""
import tkinter as tk
from tkinter import ttk, messagebox, filedialog, scrolledtext
import tkinter.font as tkfont
import json, base64, os, sys, hashlib

try:
    import qrcode
    from PIL import (ImageTk as _ITK, Image as _Img,
                     ImageDraw as _IDraw, ImageFont as _IFont,
                     ImageEnhance as _IEnhance)
    _HAS_QR = True
except ImportError:
    _HAS_QR = False

# ── Constants ─────────────────────────────────────────────────────────────────
W_MM   = 51.5
H_MM   = 29.0
DPI    = 203
SCALE  = 10          # screen pixels per mm  (zoom level)
MARGIN = 10          # canvas border in px

# TSPL font sizes (approx heights in mm at 203 DPI)
FONT_HEIGHTS = {1: 1.5, 2: 2.0, 3: 2.5, 4: 3.0, 5: 4.0}

# ── Helpers ───────────────────────────────────────────────────────────────────
def mm2px(v):  return int(v * SCALE)
def px2mm(v):  return v / SCALE
def mm2dot(v): return int(v * DPI / 25.4)

def charge_to_hex(cid):
    """Hash charge_id with MD5 → 6-char uppercase hex. Same input always = same output."""
    s = str(cid).strip() if cid is not None else ''
    if not s or s.lower() in ('null', 'none'):
        s = '0'
    try:
        s = str(int(s))   # normalise  "002" → "2"
    except ValueError:
        pass
    return hashlib.md5(s.encode()).hexdigest()[:6].upper()

# ── Element model ─────────────────────────────────────────────────────────────
class Elem:
    def __init__(self, kind, x, y, **props):
        self.kind  = kind   # 'text' | 'qr' | 'line'
        self.x     = x      # mm
        self.y     = y      # mm
        self.props = props
        self.tag   = None   # canvas tag string

    def copy_pos(self, other):
        other.x = self.x
        other.y = self.y

# ── Main app ──────────────────────────────────────────────────────────────────
class LabelDesigner:

    SAMPLE = {
        'uuid':        'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        'code':        'GS-145',
        'description': 'Clip De Tapiceria Toyota',
        'charge_id':   1472777,   # hex → 167909
        'content':     {'quantity': 25},
    }

    def __init__(self, root, init_data=None):
        self.root     = root
        self.root.title('Label Preview – Xprinter XP-365B #2')
        self.root.resizable(False, False)
        self.data      = dict(init_data or self.SAMPLE)
        self.elems     = []
        self.selected  = None
        self._drag     = None          # (start_x_px, start_y_px, elem_x0, elem_y0)
        self._qr_cache  = {}             # {(data,size_px): PhotoImage}
        self._bg_photo  = None           # reference background PhotoImage
        self._bg_pil    = None           # original PIL image for re-rendering
        self._screen_dpi = root.winfo_fpixels('1i')  # e.g. 96 or 120

        self._build_menu()
        self._build_left()            # canvas
        self._build_right()           # properties
        self._refresh_elems(init=True)
        self._redraw()

    # ── Menu ──────────────────────────────────────────────────────────────────
    def _build_menu(self):
        mb = tk.Menu(self.root)
        fm = tk.Menu(mb, tearoff=0)
        fm.add_command(label='Cargar JSON…',   command=self._load_json)
        fm.add_command(label='Guardar JSON…',  command=self._save_json)
        fm.add_separator()
        fm.add_command(label='Ver TSPL…',      command=self._show_tspl)
        fm.add_separator()
        fm.add_command(label='Cargar fondo de referencia…', command=self._load_bg_image)
        fm.add_command(label='Quitar fondo',               command=self._clear_bg_image)
        mb.add_cascade(label='Archivo', menu=fm)
        self.root.config(menu=mb)

    # ── Canvas panel (left) ───────────────────────────────────────────────────
    def _build_left(self):
        lf = tk.Frame(self.root)
        lf.pack(side=tk.LEFT, padx=12, pady=12)

        tk.Label(lf, text=f'{W_MM} mm × {H_MM} mm   (zoom {SCALE}×)',
                 font=('Arial', 8), fg='gray').pack()

        self.canvas = tk.Canvas(
            lf,
            width=mm2px(W_MM) + MARGIN * 2,
            height=mm2px(H_MM) + MARGIN * 2,
            bg='white', highlightthickness=1, highlightbackground='#999'
        )
        self.canvas.pack()
        self.canvas.bind('<ButtonPress-1>',   self._on_press)
        self.canvas.bind('<B1-Motion>',       self._on_drag)
        self.canvas.bind('<ButtonRelease-1>', self._on_release)
        self.canvas.bind('<Double-Button-1>', self._on_dblclick)

        bf = tk.Frame(lf)
        bf.pack(pady=6)
        tk.Button(bf, text='Imprimir', command=self._print,
                  bg='#28a745', fg='white', padx=8).pack(side=tk.LEFT, padx=4)
        tk.Button(bf, text='Ver TSPL', command=self._show_tspl,
                  padx=8).pack(side=tk.LEFT, padx=4)
        tk.Button(bf, text='Exportar JSON', command=self._save_json,
                  padx=8).pack(side=tk.LEFT, padx=4)

    # ── Properties panel (right) ──────────────────────────────────────────────
    def _build_right(self):
        rf = tk.Frame(self.root, relief=tk.GROOVE, bd=1)
        rf.pack(side=tk.LEFT, padx=6, pady=12, fill=tk.Y)

        # Two columns side by side
        col1 = tk.Frame(rf)
        col1.pack(side=tk.LEFT, fill=tk.Y, padx=4, pady=6)
        col2 = tk.Frame(rf)
        col2.pack(side=tk.LEFT, fill=tk.Y, padx=4, pady=6, anchor='n')

        # ── COLUMN 1: Datos + Elemento ─────────────────────────────────────
        tk.Label(col1, text='Datos', font=('Arial', 9, 'bold')).pack(pady=(2, 2))

        self._dvars = {}
        for label, key in [
            ('Código',      'code'),
            ('Descripción', 'description'),
            ('Carga ID',    'charge_id'),
            ('Cantidad',    'quantity'),
            ('QR / Texto',  'qr_text'),
        ]:
            r = tk.Frame(col1)
            r.pack(fill=tk.X, pady=1)
            tk.Label(r, text=label + ':', width=11, anchor='w').pack(side=tk.LEFT)
            var = tk.StringVar(value=self._dget(key))
            var.trace_add('write', lambda *_, k=key, v=var: self._on_data(k, v))
            self._dvars[key] = var
            tk.Entry(r, textvariable=var, width=14).pack(side=tk.LEFT)

        ttk.Separator(col1, orient='horizontal').pack(fill=tk.X, pady=6)

        tk.Label(col1, text='Elemento', font=('Arial', 9, 'bold')).pack(pady=(0, 2))
        self._sel_lbl = tk.Label(col1, text='(clic para selec.)', fg='gray', wraplength=175, font=('Arial', 8))
        self._sel_lbl.pack(anchor='w')

        grid = tk.Frame(col1)
        grid.pack(fill=tk.X, pady=2)

        self._ex  = tk.DoubleVar()
        self._ey  = tk.DoubleVar()
        self._ef  = tk.DoubleVar(value=3.0)
        self._es  = tk.DoubleVar(value=14.0)
        self._eln = tk.DoubleVar(value=48.0)
        self._eth = tk.DoubleVar(value=0.5)

        specs = [
            ('X (mm)',        self._ex,  0.0,  W_MM, 0.5),
            ('Y (mm)',        self._ey,  0.0,  H_MM, 0.5),
            ('Fuente',        self._ef,  0.2,  30.0, 0.2),
            ('Tam. QR',       self._es,  0.5,  50.0, 0.1),
            ('Longitud',      self._eln, 1.0,  W_MM, 0.5),
            ('Grosor',        self._eth, 0.1,  5.0,  0.1),
        ]
        self._spec_labels  = []
        self._spec_widgets = []
        for i, (lbl, var, mn, mx, inc) in enumerate(specs):
            lw = tk.Label(grid, text=lbl, anchor='w', width=9, font=('Arial', 8))
            lw.grid(row=i, column=0, sticky='w', pady=1)
            sb = tk.Spinbox(grid, from_=mn, to=mx, textvariable=var,
                            width=7, increment=inc, command=self._apply_elem, font=('Arial', 8))
            sb.grid(row=i, column=1, sticky='w', pady=1)
            sb.bind('<Return>',    lambda e: self._apply_elem())
            sb.bind('<FocusOut>', lambda e: self._apply_elem())
            self._spec_labels.append(lw)
            self._spec_widgets.append(sb)

        extra = tk.Frame(col1)
        extra.pack(fill=tk.X, pady=2)

        self._e_anchor  = tk.StringVar(value='nw')
        self._e_style   = tk.StringVar(value='Normal')
        self._e_wrap    = tk.DoubleVar(value=20.0)
        self._e_justify = tk.StringVar(value='left')
        self._e_spacing = tk.IntVar(value=0)

        eg = tk.Frame(extra)
        eg.pack(fill=tk.X)
        for i, (lbl, var, vals_or_none, w_cb, mn, mx, inc) in enumerate([
            ('Anchor',  self._e_anchor,  ['nw','n','ne','w','center','e','sw','s','se'], 8, None, None, None),
            ('Estilo',  self._e_style,   ['Light','Normal','Bold','Black'],              8, None, None, None),
            ('Alinea.', self._e_justify, ['left','center','right'],                     8, None, None, None),
            ('Wrap mm', self._e_wrap,    None, None, 0,   W_MM, 0.5),
            ('Interl.', self._e_spacing, None, None, -20, 80,   1),
        ]):
            lw = tk.Label(eg, text=lbl+':', anchor='w', width=7, font=('Arial', 8))
            lw.grid(row=i, column=0, sticky='w', pady=1)
            if vals_or_none is not None:
                w = ttk.Combobox(eg, textvariable=var, values=vals_or_none,
                                 width=w_cb, state='readonly', font=('Arial', 8))
                w.bind('<<ComboboxSelected>>', lambda e: self._apply_elem())
            else:
                w = tk.Spinbox(eg, from_=mn, to=mx, textvariable=var,
                               width=7, increment=inc, command=self._apply_elem, font=('Arial', 8))
                w.bind('<FocusOut>', lambda e: self._apply_elem())
            w.grid(row=i, column=1, sticky='w', pady=1)
            self._spec_labels.append(lw)
            self._spec_widgets.append(w)

        # ── COLUMN 2: Fondo + Impresión ────────────────────────────────────
        tk.Label(col2, text='Fondo ref.', font=('Arial', 9, 'bold')).pack(pady=(2, 2))

        self._bg_x     = tk.DoubleVar(value=0.0)
        self._bg_y     = tk.DoubleVar(value=0.0)
        self._bg_scale = tk.DoubleVar(value=100.0)
        self._bg_op    = tk.IntVar(value=25)

        bg_grid = tk.Frame(col2)
        bg_grid.pack(fill=tk.X, pady=2)
        for i, (lbl, var, mn, mx, inc) in enumerate([
            ('X (mm)',   self._bg_x,     -W_MM, W_MM, 0.5),
            ('Y (mm)',   self._bg_y,     -H_MM, H_MM, 0.5),
            ('Escala %', self._bg_scale, 5.0,   500,  5.0),
            ('Opac. %',  self._bg_op,   0,      100,  5),
        ]):
            tk.Label(bg_grid, text=lbl+':', width=7, anchor='w', font=('Arial', 8)).grid(
                row=i, column=0, sticky='w', pady=1)
            sb = tk.Spinbox(bg_grid, from_=mn, to=mx, textvariable=var,
                            width=6, increment=inc, command=self._apply_bg, font=('Arial', 8))
            sb.grid(row=i, column=1, sticky='w', pady=1)
            sb.bind('<FocusOut>', lambda e: self._apply_bg())

        self._bg_size_lbl = tk.Label(bg_grid, text='', fg='gray', font=('Arial', 7))
        self._bg_size_lbl.grid(row=4, column=0, columnspan=2, sticky='w')

        rbg = tk.Frame(col2)
        rbg.pack(fill=tk.X, pady=2)
        tk.Button(rbg, text='Cargar…', width=7, font=('Arial', 8),
                  command=self._load_bg_image).pack(side=tk.LEFT, padx=(0, 2))
        tk.Button(rbg, text='Quitar', font=('Arial', 8),
                  command=self._clear_bg_image).pack(side=tk.LEFT)

        ttk.Separator(col2, orient='horizontal').pack(fill=tk.X, pady=6)

        tk.Label(col2, text='Impresión', font=('Arial', 9, 'bold')).pack(pady=(0, 2))

        pg = tk.Frame(col2)
        pg.pack(fill=tk.X, pady=2)

        self._copies    = tk.IntVar(value=1)
        self._direction = tk.IntVar(value=1)
        self._print_ox  = tk.DoubleVar(value=0.0)
        self._print_oy  = tk.DoubleVar(value=0.0)

        for i, (lbl, var, widget_type, kw) in enumerate([
            ('Copias',    self._copies,    'spin', dict(from_=1,    to=99,   increment=1,   width=5)),
            ('Direcc.',   self._direction, 'combo',dict(values=[0,1], width=3)),
            ('Offset X',  self._print_ox,  'spin', dict(from_=-20,  to=20,   increment=0.5, width=5)),
            ('Offset Y',  self._print_oy,  'spin', dict(from_=-20,  to=20,   increment=0.5, width=5)),
        ]):
            tk.Label(pg, text=lbl+':', anchor='w', width=7, font=('Arial', 8)).grid(
                row=i, column=0, sticky='w', pady=1)
            if widget_type == 'combo':
                ttk.Combobox(pg, textvariable=var, state='readonly', font=('Arial', 8),
                             **kw).grid(row=i, column=1, sticky='w', pady=1)
            else:
                tk.Spinbox(pg, textvariable=var, font=('Arial', 8),
                           **kw).grid(row=i, column=1, sticky='w', pady=1)

        rp = tk.Frame(col2)
        rp.pack(fill=tk.X, pady=1)
        tk.Label(rp, text='Impr.:', anchor='w', width=7, font=('Arial', 8)).pack(side=tk.LEFT)
        self._printer_var = tk.StringVar(value=self._get_default_printer())
        tk.Entry(rp, textvariable=self._printer_var, width=14, font=('Arial', 8)).pack(side=tk.LEFT)

    # ── Data helpers ──────────────────────────────────────────────────────────
    def _dget(self, key):
        if key == 'quantity':
            return str(self.data.get('content', {}).get('quantity', 0))
        if key == 'qr_text':
            return str(self.data.get('qr_text', self.data.get('uuid', '')))
        return str(self.data.get(key, ''))

    def _on_data(self, key, var):
        val = var.get()
        if key == 'quantity':
            try:
                self.data.setdefault('content', {})['quantity'] = int(val)
            except ValueError:
                return
        else:
            self.data[key] = val
        self._refresh_elems()
        self._redraw()

    def _refresh_dvars(self):
        for key, var in self._dvars.items():
            var.set(self._dget(key))

    # ── Element helpers ───────────────────────────────────────────────────────
    def _refresh_elems(self, init=False):
        """Build or update elements based on data."""
        code  = str(self.data.get('code', ''))
        desc  = str(self.data.get('description', ''))
        qty   = self.data.get('content', {}).get('quantity', 0)
        cid   = str(self.data.get('charge_id', ''))
        cb = f'CB-{charge_to_hex(cid)}'
            
        qr_text = self._dget('qr_text')

        if init or not self.elems:
            self.elems = [
                Elem('qr',    2.0,  1.5, size=14, bind='qr', text=qr_text,
                     anchor='nw'),
                Elem('text',  2.0, 17.0, text=code, font=4, bind='code',
                     anchor='nw', style='Bold',  wrap=0),
                Elem('text',  2.0, 21.5, text=cb,   font=3, bind='cb',
                     anchor='nw', style='Normal', wrap=0),
                Elem('line', 28.0,  0.0, direction='v', length=H_MM, thickness=0.5),
                Elem('text', 30.0,  1.5, text=desc, font=2, bind='desc',
                     anchor='nw', style='Normal', wrap=20.0),
                Elem('text', 30.0, 21.0, text=f'CANT: {int(qty)} UND.', font=3, bind='qty',
                     anchor='nw', style='Normal', wrap=0),
            ]
            for e in self.elems:
                e.tag = f'elem_{id(e)}'
        else:
            for e in self.elems:
                if e.props.get('bind') == 'code':
                    e.props['text'] = code
                elif e.props.get('bind') == 'desc':
                    e.props['text'] = desc
                elif e.props.get('bind') == 'cb':
                    e.props['text'] = cb
                elif e.props.get('bind') == 'qty':
                    e.props['text'] = f'CANT: {int(qty)} UND.'
                elif e.props.get('bind') == 'qr' or e.kind == 'qr':
                    e.props['text'] = qr_text

    # ── Canvas drawing ────────────────────────────────────────────────────────
    def _redraw(self):
        c = self.canvas
        c.delete('all')

        ox, oy = MARGIN, MARGIN

        # Background reference image (not printed) — drawn first so border stays on top
        if self._bg_photo:
            bx = ox + mm2px(self._bg_x.get())
            by = oy + mm2px(self._bg_y.get())
            c.create_image(bx, by, image=self._bg_photo, anchor='nw')

        # Label border (always on top of background)
        c.create_rectangle(ox, oy,
                           ox + mm2px(W_MM), oy + mm2px(H_MM),
                           outline='black', width=2)

        for el in self.elems:
            self._draw_elem(el, ox, oy)

        self._update_sel_panel()

    def _canvas_wrap(self, text, fnt, max_px):
        """Word-wrap text to fit max_px using a tkinter Font object."""
        words, lines, cur = text.split(), [], ''
        for w in words:
            test = (cur + ' ' + w).strip()
            if fnt.measure(test) <= max_px:
                cur = test
            else:
                if cur: lines.append(cur)
                cur = w
        if cur: lines.append(cur)
        return lines or [text]

    def _make_qr(self, data, size_px):
        """Return a cached PhotoImage for the QR code, or None if qrcode not installed."""
        if not _HAS_QR:
            return None
        key = (data, size_px)
        if key not in self._qr_cache:
            try:
                qr = qrcode.QRCode(
                    version=None,
                    error_correction=qrcode.constants.ERROR_CORRECT_H,
                    box_size=2, border=1
                )
                qr.add_data(data)
                qr.make(fit=True)
                img = qr.make_image(fill_color='black', back_color='white').convert('RGB')
                img = img.resize((size_px, size_px), _Img.NEAREST)
                self._qr_cache[key] = _ITK.PhotoImage(img)
            except Exception:
                return None
        return self._qr_cache.get(key)

    def _draw_elem(self, el, ox, oy):
        c    = self.canvas
        x    = ox + mm2px(el.x)
        y    = oy + mm2px(el.y)
        sel  = (el is self.selected)
        col  = '#0066cc' if sel else 'black'
        tag  = el.tag

        if el.kind == 'qr':
            sz  = mm2px(el.props.get('size', 14))
            anc = el.props.get('anchor', 'nw')
            qr_data = str(el.props.get('text', '')) or 'QR'
            img = self._make_qr(qr_data, sz)
            if img:
                c.create_image(x, y, image=img, anchor=anc, tags=tag)
            else:
                c.create_rectangle(x, y, x + sz, y + sz,
                                   outline=col, width=2 if sel else 1,
                                   dash=(4, 2) if not sel else (), tags=tag)
                c.create_text(x + sz // 2, y + sz // 2,
                              text='[ QR ]', font=('Courier', 8), fill='gray', tags=tag)
            if sel:
                c.create_rectangle(x - 3, y - 3, x + sz + 3, y + sz + 3,
                                   outline=col, width=1, tags=tag)
            c.create_rectangle(x - 4, y - 4, x + sz + 4, y + sz + 4,
                               outline='', fill='', tags=tag)

        elif el.kind == 'text':
            text    = el.props.get('text', '')
            fs      = max(7, int(round(el.props.get('font', 3.0) * 4)))
            style   = el.props.get('style', 'Normal')
            anc     = el.props.get('anchor', 'nw')
            wrap    = el.props.get('wrap', 0)
            spacing = el.props.get('spacing', 0)
            if style == 'Black':
                font_spec = ('Arial Black', fs)
            elif style == 'Bold':
                font_spec = ('Arial', fs, 'bold')
            elif style == 'Light':
                font_spec = ('Arial', fs)
            else:
                font_spec = ('Arial', fs)
            wrap_px = mm2px(wrap) if wrap and wrap > 0 else 0
            justify = el.props.get('justify', 'left')
            if wrap_px and spacing != 0:
                # manual multiline with custom line height
                fnt     = tkfont.Font(family=font_spec[0], size=font_spec[1],
                                      weight=font_spec[2] if len(font_spec)>2 else 'normal')
                lines   = self._canvas_wrap(text, fnt, wrap_px)
                line_h  = fnt.metrics('linespace') + spacing
                for i, ln in enumerate(lines):
                    c.create_text(x, y + i * line_h, text=ln, font=font_spec,
                                  anchor=anc, fill=col, tags=tag)
            else:
                kw = dict(text=text, font=font_spec, anchor=anc, fill=col,
                          justify=justify, tags=tag)
                if wrap_px:
                    kw['width'] = wrap_px
                c.create_text(x, y, **kw)
            if sel:
                c.create_rectangle(x - 4, y - 4, x + 8, y + 8,
                                   outline=col, fill=col, tags=tag)
            c.create_rectangle(x - 6, y - 6, x + mm2px(5), y + mm2px(5),
                               outline='', fill='', tags=tag)

        elif el.kind == 'line':
            direction = el.props.get('direction', 'h')
            anc       = el.props.get('anchor', 'nw')
            th        = max(1, int(mm2px(el.props.get('thickness', 0.5))))
            pad       = max(6, th + 4)
            if direction == 'v':
                length = mm2px(el.props.get('length', H_MM))
                # vertical anchor offset: n=top, center=middle, s=bottom
                if anc in ('center', 'w', 'e'):
                    y -= length // 2
                elif anc in ('s', 'sw', 'se'):
                    y -= length
                c.create_line(x, y, x, y + length, width=th, fill=col, tags=tag)
                c.create_rectangle(x - pad, y, x + pad, y + length,
                                   outline='', fill='', tags=tag)
            else:
                length = mm2px(el.props.get('length', W_MM))
                # horizontal anchor offset: w=left, center=middle, e=right
                if anc in ('center', 'n', 's'):
                    x -= length // 2
                elif anc in ('e', 'ne', 'se'):
                    x -= length
                c.create_line(x, y, x + length, y, width=th, fill=col, tags=tag)
                c.create_rectangle(x, y - pad, x + length, y + pad,
                                   outline='', fill='', tags=tag)

        # tag bindings for drag (fires before canvas-level binding)
        c.tag_bind(tag, '<ButtonPress-1>', lambda e, el=el: self._select(el, e))
        c.tag_bind(tag, '<B1-Motion>',     self._on_drag)

    # ── Interaction ───────────────────────────────────────────────────────────
    def _select(self, el, event):
        self.selected = el
        self._drag    = (event.x, event.y, el.x, el.y)
        self._update_sel_panel()
        self._redraw()

    def _on_press(self, event):
        # If an element's tag_bind already handled this click, don't deselect.
        tol   = 8
        items = self.canvas.find_overlapping(
            event.x - tol, event.y - tol, event.x + tol, event.y + tol)
        for item in items:
            tags = self.canvas.gettags(item)
            for el in self.elems:
                if el.tag in tags:
                    return   # element handled it — do nothing here
        # Empty area → deselect
        self.selected = None
        self._drag    = None
        self._update_sel_panel()
        self._redraw()

    def _on_drag(self, event):
        if self.selected and self._drag:
            sx, sy, ex0, ey0 = self._drag
            dx = px2mm(event.x - sx)
            dy = px2mm(event.y - sy)
            self.selected.x = round(max(0, min(W_MM, ex0 + dx)), 1)
            self.selected.y = round(max(0, min(H_MM, ey0 + dy)), 1)
            self._ex.set(self.selected.x)
            self._ey.set(self.selected.y)
            self._redraw()

    def _on_release(self, event):
        if self._drag and self.selected:
            # Snap _drag base to current position for next drag
            self._drag = (event.x, event.y, self.selected.x, self.selected.y)

    def _on_dblclick(self, event):
        # Double-click text → quick edit
        if self.selected and self.selected.kind == 'text':
            self._inline_edit()

    def _inline_edit(self):
        el = self.selected
        win = tk.Toplevel(self.root)
        win.title('Editar texto')
        win.grab_set()
        var = tk.StringVar(value=el.props.get('text', ''))
        e   = tk.Entry(win, textvariable=var, width=40)
        e.pack(padx=10, pady=10)
        e.focus()

        def ok():
            el.props['text'] = var.get()
            win.destroy()
            self._redraw()

        e.bind('<Return>', lambda _: ok())
        tk.Button(win, text='OK', command=ok).pack(pady=4)

    def _update_sel_panel(self):
        # (label_text, active_for_kinds)  – None means always active
        SPEC_META = [
            ('X (mm)',         None),
            ('Y (mm)',         None),
            ('Fuente (txt)',   ('text',)),
            ('Tamaño (QR)',    ('qr',)),
            ('Longitud (lín)', ('line',)),
            ('Grosor (lín)',   ('line',)),
            ('Anchor',         ('text', 'qr', 'line')),
            ('Estilo',         ('text',)),
            ('Alineac.',       ('text',)),
            ('Ancho wrap',     ('text',)),
            ('Interl. px',     ('text',)),
        ]
        if self.selected:
            el   = self.selected
            info = el.kind
            if el.kind == 'text':
                info += f': "{el.props.get("text","")[:22]}"'
            self._sel_lbl.config(text=info, fg='black')
            self._ex.set(round(el.x, 1))
            self._ey.set(round(el.y, 1))
            if el.kind == 'text':
                self._ef.set(el.props.get('font', 3))
                self._e_anchor.set(el.props.get('anchor', 'nw'))
                self._e_style.set(el.props.get('style', 'Normal'))
                self._e_justify.set(el.props.get('justify', 'left'))
                self._e_wrap.set(round(el.props.get('wrap', 0), 1))
                self._e_spacing.set(el.props.get('spacing', 0))
            if el.kind == 'qr':
                self._es.set(round(el.props.get('size', 14.0), 1))
                self._e_anchor.set(el.props.get('anchor', 'nw'))
            if el.kind == 'line':
                self._eln.set(round(el.props.get('length', H_MM), 1))
                self._eth.set(round(el.props.get('thickness', 0.5), 2))
                self._e_anchor.set(el.props.get('anchor', 'nw'))
        else:
            self._sel_lbl.config(text='(clic para seleccionar)', fg='gray')

        kind = self.selected.kind if self.selected else ''
        for i, (_, kinds) in enumerate(SPEC_META):
            active = kinds is None or kind in kinds
            fg     = 'black' if active else '#aaa'
            state  = 'normal' if active else 'disabled'
            self._spec_labels[i].config(fg=fg)
            self._spec_widgets[i].config(state=state)

    def _apply_elem(self):
        if not self.selected:
            return
        el = self.selected
        try:
            el.x = float(self._ex.get())
            el.y = float(self._ey.get())
            if el.kind == 'text':
                el.props['font']    = round(float(self._ef.get()), 1)
                el.props['anchor']  = self._e_anchor.get()
                el.props['style']   = self._e_style.get()
                el.props['justify'] = self._e_justify.get()
                el.props['wrap']    = float(self._e_wrap.get())
                el.props['spacing'] = int(self._e_spacing.get())
            elif el.kind == 'qr':
                el.props['size']   = round(float(self._es.get()), 1)
                el.props['anchor'] = self._e_anchor.get()
            elif el.kind == 'line':
                el.props['length']    = float(self._eln.get())
                el.props['thickness'] = float(self._eth.get())
                el.props['anchor']    = self._e_anchor.get()
            self._redraw()
        except ValueError:
            pass

    # ── Image rendering ─────────────────────────────────────────────────────────────
    def _get_font(self, style, font_num, dpi):
        # font_num*4 = tkinter POINT size; convert to screen px using screen DPI
        pt_size    = font_num * 4
        screen_px  = pt_size * self._screen_dpi / 72.0   # actual screen pixels
        height_mm  = screen_px / SCALE                    # mm on the label
        size_px    = max(8, int(height_mm * dpi / 25.4))
        paths = {
            'Bold':  'C:/Windows/Fonts/arialbd.ttf',
            'Light': 'C:/Windows/Fonts/arial.ttf',
            'Black': 'C:/Windows/Fonts/ariblk.ttf',
        }
        path = paths.get(style, 'C:/Windows/Fonts/arial.ttf')
        try:
            return _IFont.truetype(path, size_px)
        except Exception:
            return _IFont.load_default()

    def _wrap_text(self, draw, text, font, max_px):
        words, lines, cur = text.split(), [], ''
        for w in words:
            test = (cur + ' ' + w).strip()
            try:    bx = draw.textbbox((0, 0), test, font=font); width = bx[2] - bx[0]
            except: width, _ = draw.textsize(test, font=font)
            if width <= max_px:
                cur = test
            else:
                if cur: lines.append(cur)
                cur = w
        if cur: lines.append(cur)
        return lines or [text]

    def render_to_image(self, dpi=DPI):
        """Render label design to a PIL RGB Image at the given DPI."""
        ANC = {'nw':'lt','n':'mt','ne':'rt','w':'lm','center':'mm',
               'e':'rm','sw':'lb','s':'mb','se':'rb'}
        scale = dpi / 25.4
        img   = _Img.new('RGB', (int(W_MM*scale), int(H_MM*scale)), 'white')
        draw  = _IDraw.Draw(img)

        for el in self.elems:
            ex = int(el.x * scale)
            ey = int(el.y * scale)

            if el.kind == 'qr' and _HAS_QR:
                sz  = int(el.props.get('size', 14) * scale)
                dat = str(el.props.get('text', '')) or 'QR'
                qr  = qrcode.QRCode(version=None,
                      error_correction=qrcode.constants.ERROR_CORRECT_H,
                      box_size=2, border=1)
                qr.add_data(dat); qr.make(fit=True)
                qi = qr.make_image(fill_color='black',
                                   back_color='white').convert('RGB')
                qi = qi.resize((sz, sz), _Img.NEAREST)
                anc = el.props.get('anchor', 'nw')
                ax  = ex - (sz//2 if anc in ('n','center','s') else
                            (sz  if anc in ('ne','e','se') else 0))
                ay  = ey - (sz//2 if anc in ('w','center','e') else
                            (sz  if anc in ('sw','s','se') else 0))
                img.paste(qi, (max(0,ax), max(0,ay)))

            elif el.kind == 'text':
                text = str(el.props.get('text', ''))
                if not text: continue
                font    = self._get_font(el.props.get('style','Normal'),
                                         el.props.get('font', 3), dpi)
                anc_pil = ANC.get(el.props.get('anchor','nw'), 'lt')
                justify = el.props.get('justify', 'left')
                wrap_mm = el.props.get('wrap', 0)
                spacing_px = el.props.get('spacing', 0)
                if wrap_mm and wrap_mm > 0:
                    lines  = self._wrap_text(draw, text, font,
                                             int(wrap_mm * scale))
                    draw.multiline_text((ex, ey), '\n'.join(lines),
                                       font=font, fill='black',
                                       align=justify,
                                       spacing=spacing_px)
                else:
                    draw.text((ex, ey), text, font=font,
                              fill='black', anchor=anc_pil)

            elif el.kind == 'line':
                direction = el.props.get('direction', 'h')
                anc = el.props.get('anchor', 'nw')
                th  = max(1, int(el.props.get('thickness', 0.5) * scale))
                if direction == 'v':
                    length = int(el.props.get('length', H_MM) * scale)
                    if anc in ('center','w','e'): ey -= length // 2
                    elif anc in ('s','sw','se'):  ey -= length
                    draw.rectangle([ex, ey, ex+th-1, ey+length], fill='black')
                else:
                    length = int(el.props.get('length', W_MM) * scale)
                    if anc in ('center','n','s'): ex -= length // 2
                    elif anc in ('e','ne','se'):  ex -= length
                    draw.rectangle([ex, ey, ex+length, ey+th-1], fill='black')
        return img

    def _get_tspl_image(self, copies=1):
        """Render label as 1-bit bitmap and embed in TSPL BITMAP command."""
        img     = self.render_to_image(DPI)
        bw      = img.convert('1')
        w, h    = bw.size
        wb      = (w + 7) // 8
        pixels  = list(bw.getdata())
        raw     = bytearray()
        for row in range(h):
            for bc in range(wb):
                byte = 0
                for bit in range(8):
                    col = bc * 8 + bit
                    if col >= w:
                        byte |= (1 << (7 - bit))  # padding bits → bit=1 = no print (white)
                    elif pixels[row*w+col] == 0:
                        pass                       # black pixel → bit=0 = print
                    else:
                        byte |= (1 << (7 - bit))  # white pixel → bit=1 = no print
                raw.append(byte)
        direction = self._direction.get()
        ox_dot = int(self._print_ox.get() * DPI / 25.4)
        oy_dot = int(self._print_oy.get() * DPI / 25.4)
        header = (
            f'SIZE {W_MM} mm, {H_MM} mm\r\n'
            f'GAP 2 mm, 0\r\n'
            f'DIRECTION {direction}\r\n'
            f'REFERENCE 0,0\r\n'
            f'OFFSET 0 mm\r\n'
            f'CLS\r\n'
            f'BITMAP {ox_dot},{oy_dot},{wb},{h},0,'
        ).encode('ascii')
        footer = f'\r\nPRINT {copies}\r\n'.encode('ascii')
        return header + bytes(raw) + footer

    # ── TSPL generation ─────────────────────────────────────────────────────────────
    def _get_tspl(self, copies=1):
        direction = getattr(self, '_direction', tk.IntVar(value=1)).get()
        cmds   = [
            f'SIZE {W_MM} mm, {H_MM} mm',
            'GAP 2 mm, 0',
            f'DIRECTION {direction}',
            'REFERENCE 0,0',
            'CLS',
        ]
        for el in self.elems:
            x = mm2dot(el.x)
            y = mm2dot(el.y)
            if el.kind == 'qr':
                qr_str = str(el.props.get('text', '')).replace('"', "'")
                cell_width = max(1, el.props.get('size', 14) // 3)
                cmds.append(f'QRCODE {x},{y},H,{cell_width},A,0,"{qr_str}"')
            elif el.kind == 'text':
                text  = str(el.props.get('text', '')).replace('"', "'")
                fs    = el.props.get('font', 3)
                bold  = el.props.get('style', 'Normal') == 'Bold'
                if bold:
                    cmds.append('BOLD 1')
                cmds.append(f'TEXT {x},{y},"{fs}",0,1,1,"{text}"')
                if bold:
                    cmds.append('BOLD 0')
            elif el.kind == 'line':
                direction = el.props.get('direction', 'h')
                th = max(1, mm2dot(el.props.get('thickness', 0.5)))
                if direction == 'v':
                    length = mm2dot(el.props.get('length', H_MM))
                    cmds.append(f'BAR {x},{y},{th},{length}')
                else:
                    length = mm2dot(el.props.get('length', W_MM))
                    cmds.append(f'BAR {x},{y},{length},{th}')
        cmds.append(f'PRINT {copies}')
        cmds.append('')
        return '\r\n'.join(cmds)

    # ── Actions ───────────────────────────────────────────────────────────────
    def _show_tspl(self):
        tspl = self._get_tspl(self._copies.get())
        win  = tk.Toplevel(self.root)
        win.title('TSPL Commands')
        st = scrolledtext.ScrolledText(win, width=55, height=22, font=('Courier', 9))
        st.pack(padx=10, pady=10)
        st.insert('1.0', tspl)
        tk.Button(win, text='Copiar al portapapeles',
                  command=lambda: (win.clipboard_clear(),
                                   win.clipboard_append(tspl))).pack(pady=4)

    def _print(self):
        printer = self._printer_var.get().strip() or None
        try:
            import win32print
            p    = printer or win32print.GetDefaultPrinter()
            data = self._get_tspl_image(self._copies.get())
            h    = win32print.OpenPrinter(p)
            try:
                win32print.StartDocPrinter(h, 1, ('Label', None, 'RAW'))
                try:
                    win32print.StartPagePrinter(h)
                    win32print.WritePrinter(h, data)
                    win32print.EndPagePrinter(h)
                finally:
                    win32print.EndDocPrinter(h)
            finally:
                win32print.ClosePrinter(h)
            messagebox.showinfo('Listo', f'Imagen enviada a: {p}\nCopias: {self._copies.get()}')
        except ImportError:
            messagebox.showerror('Error', 'win32print no instalado.\nInstala: pip install pywin32')
        except Exception as exc:
            messagebox.showerror('Error al imprimir', str(exc))

    def _load_bg_image(self):
        if not _HAS_QR:
            messagebox.showerror('Error', 'Pillow no instalado.\nInstala: pip install pillow')
            return
        path = filedialog.askopenfilename(
            title='Imagen de referencia (no se imprime)',
            filetypes=[('Imágenes', '*.png *.jpg *.jpeg *.bmp *.gif'), ('Todos', '*.*')]
        )
        if not path: return
        self._bg_pil = _Img.open(path).convert('RGB')
        self._apply_bg()

    def _apply_bg(self):
        if self._bg_pil is None:
            return
        ow, oh  = self._bg_pil.size          # original pixels
        scale   = max(0.05, self._bg_scale.get() / 100.0)
        w_px    = max(1, int(ow * scale))
        h_px    = max(1, int(oh * scale))
        w_mm    = round(px2mm(w_px), 1)
        h_mm    = round(px2mm(h_px), 1)
        resized = self._bg_pil.resize((w_px, h_px), _Img.LANCZOS)
        alpha   = max(0, min(100, self._bg_op.get())) / 100.0
        white   = _Img.new('RGB', resized.size, (255, 255, 255))
        blended = _Img.blend(white, resized, alpha=alpha)
        self._bg_photo = _ITK.PhotoImage(blended)
        self._bg_size_lbl.config(text=f'{w_mm}×{h_mm} mm  ({w_px}×{h_px}px)')
        self._redraw()

    def _clear_bg_image(self):
        self._bg_pil   = None
        self._bg_photo = None
        self._redraw()

    def _load_json(self):
        path = filedialog.askopenfilename(
            title='Cargar payload JSON',
            filetypes=[('JSON', '*.json'), ('Todos', '*.*')]
        )
        if not path:
            return
        with open(path, 'r', encoding='utf-8') as f:
            data = json.load(f)
            
        if 'layout' in data:
            self.data = data.get('data', {})
            self.elems = []
            for item in data['layout']:
                props = {k: v for k, v in item.items() if k not in ('kind', 'x', 'y')}
                e = Elem(item['kind'], item['x'], item['y'], **props)
                e.tag = f'elem_{id(e)}'
                self.elems.append(e)
            po = data.get('print_offset', {})
            self._print_ox.set(po.get('x', 0.0))
            self._print_oy.set(po.get('y', 0.0))
        else:
            self.data = data
            self._refresh_elems(init=True)
            
        self._refresh_dvars()
        self._refresh_elems()
        self._redraw()

    def _save_json(self):
        path = filedialog.asksaveasfilename(
            title='Guardar payload JSON',
            defaultextension='.json',
            filetypes=[('JSON', '*.json')]
        )
        if not path:
            return
        layout = [
            {'kind': e.kind, 'x': e.x, 'y': e.y, **e.props}
            for e in self.elems
        ]
        payload = {
            'data': self.data,
            'layout': layout,
            'print_offset': {'x': self._print_ox.get(), 'y': self._print_oy.get()},
        }
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(payload, f, indent=2, ensure_ascii=False)

    @staticmethod
    def _get_default_printer():
        try:
            import win32print
            return win32print.GetDefaultPrinter()
        except Exception:
            return 'Xprinter XP-365B #2'


# ── Entry point ───────────────────────────────────────────────────────────────
def run_preview(data=None, printer=None):
    root = tk.Tk()
    app  = LabelDesigner(root, data)
    if printer:
        app._printer_var.set(printer)
    root.mainloop()


if __name__ == '__main__':
    import argparse
    ap = argparse.ArgumentParser(description='Label Preview Designer')
    ap.add_argument('--payload',      help='JSON string')
    ap.add_argument('--payload-file', dest='payload_file')
    args = ap.parse_args()

    data = None
    if args.payload_file:
        with open(args.payload_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
    elif args.payload:
        data = json.loads(args.payload)

    run_preview(data)
