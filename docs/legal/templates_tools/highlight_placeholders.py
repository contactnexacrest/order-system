"""D-438 (owner, 26 Nov 2026): every [placeholder] in a reference template gets a yellow background,
so it is noticed and replaced before the document is used. The company phone number in a template
becomes the placeholder [+91-0000000000] (no-PII rule). Seal and signature images are kept (D-333).

Usage: python3 -I highlight_placeholders.py <in.docx> <out.docx>   (safe to run twice)
Only word/document.xml, headers and footers are rewritten; every other part is copied byte for byte."""
import copy, re, sys, zipfile
from lxml import etree

W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
q = lambda t: '{%s}%s' % (W, t)
XML_SPACE = '{http://www.w3.org/XML/1998/namespace}space'
PH = re.compile(r'\[[^\[\]\n]{1,90}\]')
PHONE = re.compile(r'\+91[ \-]?\d{4}[ \-]?\d{2}[ \-]?\d{4}|\+91[ \-]?\d{5}[ \-]?\d{5}')
AFTER_HL = ['u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath']


def run_text(r):
    return ''.join(t.text or '' for t in r.findall(q('t')))


def simple(r):
    kids = [k for k in r if k.tag != q('rPr')]
    return len(kids) == 1 and kids[0].tag == q('t')


def mark(r):
    rpr = r.find(q('rPr'))
    if rpr is None:
        rpr = etree.Element(q('rPr'))
        r.insert(0, rpr)
    if rpr.find(q('highlight')) is not None:
        return
    hl = etree.Element(q('highlight'))
    hl.set(q('val'), 'yellow')
    for i, k in enumerate(rpr):
        if etree.QName(k).localname in AFTER_HL:
            rpr.insert(i, hl)
            return
    rpr.append(hl)


def set_text(r, text):
    t = r.find(q('t'))
    t.text = text
    t.set(XML_SPACE, 'preserve')


def do_paragraph(p, counts):
    runs = [r for r in p.iter(q('r'))]
    # phone number -> placeholder (number must sit inside one run)
    for r in runs:
        if simple(r) and PHONE.search(run_text(r)):
            counts['phone'] += len(PHONE.findall(run_text(r)))
            set_text(r, PHONE.sub('[+91-0000000000]', run_text(r)))
    runs = [r for r in p.iter(q('r'))]
    texts = [run_text(r) for r in runs]
    full = ''.join(texts)
    spans = [(m.start(), m.end()) for m in PH.finditer(full) if m.group(0).strip('[] ') != '']
    if not spans:
        return
    counts['ph'] += len(spans)
    pos = 0
    for r, txt in zip(runs, texts):
        a, b = pos, pos + len(txt)
        pos = b
        if not txt:
            continue
        cuts = {a, b}
        for s, e in spans:
            if s < b and e > a:
                cuts.update(x for x in (s, e) if a < x < b)
        inside = lambda x, y: any(s <= x and y <= e for s, e in spans)
        pieces = sorted(cuts)
        segs = list(zip(pieces, pieces[1:]))
        if not any(inside(x, y) for x, y in segs):
            continue
        if len(segs) == 1 or not simple(r):
            mark(r)
            continue
        parent = r.getparent()
        idx = parent.index(r)
        for n, (x, y) in enumerate(segs):
            nr = copy.deepcopy(r)
            set_text(nr, txt[x - a:y - a])
            if inside(x, y):
                mark(nr)
            parent.insert(idx + n, nr)
        parent.remove(r)


MIME = {'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'png': 'image/png', 'gif': 'image/gif', 'emf': 'image/x-emf'}


def fix_content_types(data, names):
    # A media file whose extension has no content type makes Word report "unreadable content".
    root = etree.fromstring(data)
    ns = root.nsmap[None]
    have = {d.get('Extension').lower() for d in root if d.tag == '{%s}Default' % ns}
    for ext in sorted({n.rsplit('.', 1)[-1].lower() for n in names if n.startswith('word/media/')} - have):
        if ext in MIME:
            d = etree.SubElement(root, '{%s}Default' % ns)
            d.set('Extension', ext)
            d.set('ContentType', MIME[ext])
            root.insert(0, d)
            print(f'  added content type for .{ext}')
    return etree.tostring(root, xml_declaration=True, encoding='UTF-8', standalone=True)


def main(src, dst):
    counts = {'ph': 0, 'phone': 0}
    with zipfile.ZipFile(src) as zin:
        items = [(i, zin.read(i.filename)) for i in zin.infolist()]
    out = []
    for info, data in items:
        if re.fullmatch(r'word/(document|header\d*|footer\d*)\.xml', info.filename):
            root = etree.fromstring(data)
            for p in root.iter(q('p')):
                do_paragraph(p, counts)
            data = etree.tostring(root, xml_declaration=True, encoding='UTF-8', standalone=True)
        elif info.filename == '[Content_Types].xml':
            data = fix_content_types(data, [i.filename for i, _ in items])
        out.append((info, data))
    with zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as zout:
        for info, data in out:
            zout.writestr(info, data)
    print(f'{src}: {counts["ph"]} placeholders, {counts["phone"]} phone number(s) replaced')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
