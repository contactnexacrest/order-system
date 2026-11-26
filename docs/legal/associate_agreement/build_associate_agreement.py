"""Builds the Associate Agreement template (owner D-438, 26 Nov 2026; approved as "OK v5") in the NexaCrest house style (Commercial NDA / Sales & Supply).
Placeholders: [[text]] -> "[text]" with a yellow background. **text** -> bold navy term. Every run is Aptos, inline-formatted.
Usage: python3 build.py <out.docx>
"""
import re
import sys
from xml.sax.saxutils import escape

import docx
import io
import os
import zipfile

# The logo, the company seal, the authorised signature and the Director seal are taken from the Commercial NDA
# template (owner D-333 / D-438: they stay as they are), so no extra copy of them is kept in the repository.
_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..'))
_NDA = os.path.join(_ROOT, 'reference_templates', 'contract', 'NexaCrest_NDA_Commercial.docx')
_NDA_IMAGES = {'logo': 'word/media/9e0a8049ebf87ef45b4bed6e0b3fe6819f6995b7.jpg', 'company_seal': 'word/media/169126f1dd7945989cde2303586403d6563d6424.png',
               'signature': 'word/media/a6c9f98c305a70a92153282adf23ac209510f448.png', 'director_seal': 'word/media/db9422367a69f1c25aa0f15d56ddf24a347470d7.png'}
with zipfile.ZipFile(_NDA) as _z:
    IMG = {k: io.BytesIO(_z.read(v)) for k, v in _NDA_IMAGES.items()}
from docx.oxml import parse_xml
from docx.shared import Mm

W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
NAVY, GREY, RED, BORDER, BOXFILL, LABELFILL = '17233A', '5B6573', 'C0392B', 'D0D8E4', 'F9FAFB', 'F5F5F5'
FONT = '<w:rFonts w:ascii="Aptos" w:cs="Aptos" w:eastAsia="Aptos" w:hAnsi="Aptos"/>'


def rpr(size=18, color='000000', bold=False, italic=False, hl=False):
    return ('<w:rPr>' + FONT + ('<w:b/><w:bCs/>' if bold else '') + ('<w:i/>' if italic else '')
            + (f'<w:highlight w:val="yellow"/>' if hl else '')
            + f'<w:sz w:val="{size}"/><w:szCs w:val="{size}"/><w:color w:val="{color}"/></w:rPr>')


def runs(text, size=18, color='000000', bold=False, italic=False):
    """[[placeholder]] -> highlighted "[placeholder]"; **term** -> bold navy; {*} -> red asterisk."""
    out = []
    for part in re.split(r'(\[\[.*?\]\]|\*\*.*?\*\*|\{\*\})', text):
        if not part:
            continue
        if part.startswith('[['):
            out.append(f'<w:r>{rpr(size, "000000", True, italic, True)}<w:t xml:space="preserve">[{escape(part[2:-2])}]</w:t></w:r>')
        elif part.startswith('**'):
            out.append(f'<w:r>{rpr(size, NAVY, True, italic)}<w:t xml:space="preserve">{escape(part[2:-2])}</w:t></w:r>')
        elif part == '{*}':
            out.append(f'<w:r>{rpr(size, RED, True, italic)}<w:t xml:space="preserve"> *</w:t></w:r>')
        else:
            out.append(f'<w:r>{rpr(size, color, bold, italic)}<w:t xml:space="preserve">{escape(part)}</w:t></w:r>')
    return ''.join(out)


def para(text, size=18, color='000000', bold=False, italic=False, before=40, after=40, indent=0, jc=None, keep=False):
    ppr = f'<w:pPr>{"<w:keepNext/>" if keep else ""}<w:spacing w:before="{before}" w:after="{after}"/>'
    if indent:
        ppr += f'<w:ind w:left="{indent}" w:hanging="{360 if indent >= 360 else 0}"/>'
    if jc:
        ppr += f'<w:jc w:val="{jc}"/>'
    ppr += '</w:pPr>'
    return f'<w:p>{ppr}{runs(text, size, color, bold, italic)}</w:p>'


def tbl(grid, rows_xml, borders=True):
    b = (f'<w:tblBorders>' + ''.join(f'<w:{s} w:val="single" w:color="{BORDER}" w:sz="2"/>' for s in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV')) + '</w:tblBorders>') if borders else \
        ('<w:tblBorders>' + ''.join(f'<w:{s} w:val="none" w:sz="0" w:color="auto"/>' for s in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV')) + '</w:tblBorders>')
    g = ''.join(f'<w:gridCol w:w="{w}"/>' for w in grid)
    return f'<w:tbl {W}><w:tblPr><w:tblW w:w="{sum(grid)}" w:type="dxa"/>{b}<w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>{g}</w:tblGrid>{rows_xml}</w:tbl>'


def cell(width, inner, fill='FFFFFF', mar=(60, 120), vmerge=None):
    return (f'<w:tc><w:tcPr><w:tcW w:w="{width}" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="{fill}"/>'
            f'<w:tcMar><w:top w:w="{mar[0]}" w:type="dxa"/><w:left w:w="{mar[1]}" w:type="dxa"/><w:bottom w:w="{mar[0]}" w:type="dxa"/><w:right w:w="{mar[1]}" w:type="dxa"/></w:tcMar></w:tcPr>{inner}</w:tc>')


def row(cells_xml, cant_split=True):
    return f'<w:tr>{"<w:trPr><w:cantSplit/></w:trPr>" if cant_split else ""}{cells_xml}</w:tr>'


FULL = 9638


def bar(title):
    inner = f'<w:p><w:pPr><w:keepNext/><w:spacing w:before="0" w:after="0"/></w:pPr>{runs(title, 22, "FFFFFF", True)}</w:p>'
    return tbl([FULL], row(cell(FULL, inner, NAVY, (80, 200))), borders=False)


def box(paras_xml):
    return tbl([FULL], row(cell(FULL, paras_xml, BOXFILL, (80, 160)), cant_split=False))


def spacer():
    return '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="10"/></w:rPr><w:t></w:t></w:r></w:p>'


def clause(num, text):
    return para(f'**{num}**  {text}', before=50, after=30)


def sub(letter, text):
    return f'<w:p><w:pPr><w:spacing w:before="20" w:after="20"/><w:ind w:left="360"/></w:pPr>{runs(f"**({letter})**  " + text)}</w:p>'


def section(title, items):
    """items: list of ('c', num, text) | ('s', letter, text) | ('p', text)"""
    body = ''
    for it in items:
        if it[0] == 'c':
            body += clause(it[1], it[2])
        elif it[0] == 's':
            body += sub(it[1], it[2])
        else:
            body += para(it[1], before=30, after=30)
    # heading and its text in one table: the heading row never sits alone at the bottom of a page
    head = cell(FULL, f'<w:p><w:pPr><w:keepNext/><w:spacing w:before="0" w:after="0"/></w:pPr>{runs(title, 22, "FFFFFF", True)}</w:p>', NAVY, (80, 200))
    return tbl([FULL], row(head) + row(cell(FULL, body, BOXFILL, (80, 160)), cant_split=False)) + spacer()


def kv_table(pairs, label_w=2600):
    rows = ''
    for label, value in pairs:
        tall = 700 if label.startswith('Signature') else (220 if value.strip() == '' else 0)  # room to write by hand
        rows += row(cell(label_w, para(label, 18, NAVY, True, before=0, after=0), LABELFILL) + cell(FULL - label_w, para(value, before=0, after=tall)))
    return tbl([label_w, FULL - label_w], rows)


# ------------------------------------------------------------------ content
BRAND = '[[StoneCrest International]]'

LOGO_W = 1400
header = tbl([LOGO_W, FULL - LOGO_W], row(
    cell(LOGO_W, '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:t>__LOGO__</w:t></w:r></w:p>', 'FFFFFF', (0, 0))
    + cell(FULL - LOGO_W,
    para('NEXACREST INTERNATIONAL PRIVATE LIMITED', 26, NAVY, True, before=0, after=60, jc='right')
    + para('COMMISSION STRUCTURE AND ASSOCIATE AGREEMENT', 36, NAVY, True, before=0, after=40, jc='right')
    + para(f'{BRAND} — a division of NexaCrest International Private Limited · [[stonecrestinternational.com]]', 19, GREY, True, before=0, after=0, jc='right'),
    'FFFFFF', (0, 0))), borders=False)

intro = (para('This Agreement sets out how the Associate introduces buyers to NexaCrest and how commission is earned and paid. '
              'Please read every clause carefully before signing. Yellow fields are filled in for each Associate before the Agreement is issued.',
              17, GREY, italic=True, before=120, after=40)
         + f'<w:p><w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr>{runs("Fields marked ", 17, GREY, italic=True)}'
           f'{runs("*", 17, RED, True, True)}{runs(" are mandatory and must be completed before this document is issued.", 17, GREY, italic=True)}</w:p>')

info_cols = [2400, 2400, 2438, 2400]
info_labels = ['AGREEMENT NO.{*}', 'PLACE OF SIGNING{*}', 'EFFECTIVE DATE{*}', 'VERSION']
info_values = ['[[SC/ASA/YYYY/NNN]]', '[[Bengaluru, Karnataka, India]]', '[[DD Month YYYY]]', 'Rev.00']
info = tbl(info_cols, row(''.join(cell(w, para(l, 16, GREY, True, before=0, after=20) + para(v, 19, NAVY, True, before=0, after=0), BOXFILL)
                                 for w, l, v in zip(info_cols, info_labels, info_values))))

company = [
    '**NexaCrest International Private Limited**, a company incorporated under the Companies Act, 2013, acting through its division ' + BRAND,
    '**Registered Office:** [[Registered office address]]{*}',
    '**Corporate Office:** [[Corporate office address]]',
    '**CIN:** [[CIN]]   **GSTIN:** [[GSTIN]]',
    '**E-mail for notices:** [[notices e-mail address]]{*}',
    '**Phone:** [[+91-0000000000]]',
    '**Authorised Signatory:** [[Name — Designation]]{*}',
]
associate = [
    '**Full Legal Name:** [[Associate full legal name as per ID]]{*}',
    '**Residential Address:** [[Full residential address]]{*}',
    '**Country of Residence:** [[India / Country]]{*}',
    '**ID Document:** [[Passport / National ID / Aadhaar — number]]{*}',
    '**PAN (if resident in India):** [[PAN]]',
    '**GSTIN (if registered):** [[GSTIN / Not registered]]',
    '**E-mail for notices:** [[Associate e-mail]]{*}',
    '**Phone / WhatsApp:** [[Associate phone]]{*}',
    '**Associate Code (Portal):** [[Code]]',
]
half = FULL // 2
parties_tbl = tbl([half, FULL - half],
    row(cell(half, para('THE COMPANY ("NexaCrest")', 18, 'FFFFFF', True, before=0, after=0), NAVY)
        + cell(FULL - half, para('THE ASSOCIATE', 18, 'FFFFFF', True, before=0, after=0), NAVY))
    + row(cell(half, ''.join(para(t, before=20, after=20) for t in company), 'EFF3F7')
          + cell(FULL - half, ''.join(para(t, before=20, after=20) for t in associate), 'FFFDE7'), cant_split=False))

parties_text = box(
    para('This Commission Structure and Associate Agreement (the "**Agreement**") is made on the Effective Date stated above, at the place of signing stated above, between:')
    + para('(1)  **NexaCrest International Private Limited**, details as above (hereinafter "**NexaCrest**" or "the **Company**", which expression shall, unless repugnant to the context, include its successors and assigns); and', indent=360)
    + para('(2)  the person named above as the Associate (hereinafter "the **Associate**", which expression shall, unless repugnant to the context, include the Associate\'s heirs, executors and legal representatives, but not assigns).', indent=360)
    + para('NexaCrest and the Associate are each a "**Party**" and together the "**Parties**".'))

background = box(
    para('**A.**  NexaCrest is an export company registered in India. It works through divisions, each with its own name for its own products. This Agreement is made for the division ' + BRAND + '. The Associate\'s agreement and all payments are with and from NexaCrest International Private Limited.', indent=360)
    + para('**B.**  The Associate has relationships with potential buyers and wishes to introduce them to NexaCrest.', indent=360)
    + para('**C.**  NexaCrest wishes to engage the Associate on an independent, non-exclusive, commission-only basis to introduce buyers, on the terms of this Agreement. The Associate pays nothing to join or to stay an associate.', indent=360))

S = []  # sections

S.append(('1.  DEFINITIONS AND INTERPRETATION', [
    ('c', '1.1', 'In this Agreement the following words have the meanings given below:'),
    ('s', 'a', '**"Associate Portal"** or **"Portal"** — NexaCrest\'s online portal for associates, through which registrations, prices, orders, commission statements, notices and payment advices are given and recorded.'),
    ('s', 'b', '**"Buyer"** — any person or entity that buys, or may buy, Products from NexaCrest.'),
    ('s', 'c', '**"Client"** — a Buyer who has become the Associate\'s client under Clause 4.'),
    ('s', 'd', '**"Order"** — a purchase of Products by a Client from NexaCrest under a Proforma Invoice ("**PI**") issued by NexaCrest. Each PI is one Order unless NexaCrest states otherwise in writing.'),
    ('s', 'e', '**"FOB Value of Goods"** — the value of the goods (Products only) on NexaCrest\'s final commercial invoice for the Order, on FOB basis, line by line, after any discount, credit or claim settlement agreed by NexaCrest, and excluding freight, insurance, services, inspection, documentation, bank and all other charges, taxes and duties. Bank charges never reduce it.'),
    ('s', 'f', '**"Full Payment"** — receipt in NexaCrest\'s bank account of the entire amount due from the Client for the Order (advance, balance, letter of credit proceeds and any milestone payments), cleared and not reversed.'),
    ('s', 'g', '**"Commission"** — the amount payable to the Associate under Clause 6.'),
    ('s', 'h', '**"Commission Schedule"** — Schedule A, as changed from time to time by Rate Notice.'),
    ('s', 'i', '**"Rate Notice"** — a written notice from NexaCrest under Clause 6.8.'),
    ('s', 'j', '**"Price Answer"** — a price given to the Associate through the Portal for a product and its specification, showing a quote price, a lowest price and a valid-until date.'),
    ('s', 'k', '**"Counting Order"** — an Order that counts as a step on the commission ladder, as described in Schedule A.'),
    ('s', 'l', '**"Products"** — the goods NexaCrest offers through the division named above, as NexaCrest may change them from time to time.'),
    ('s', 'm', '**"Confidential Information"** — all information about NexaCrest, its business, Buyers, Clients, suppliers, factories, quarries, transporters, prices, Price Answers, costs, margins, commission rates, the Portal and its content, and the terms of this Agreement, in any form.'),
    ('s', 'n', '**"Working Day"** — a day other than Sunday or a public holiday in Karnataka, India.'),
    ('c', '1.2', 'Headings are for convenience only. "Including" means "including without limitation". "In writing" includes e-mail and messages through the Portal. The Schedules form part of this Agreement.'),
]))

S.append(('2.  APPOINTMENT AND NATURE OF THE RELATIONSHIP', [
    ('c', '2.1', 'NexaCrest appoints the Associate, and the Associate accepts appointment, as an **independent, non-exclusive associate** to introduce Buyers to NexaCrest, paid by commission only.'),
    ('c', '2.2', 'Nothing in this Agreement creates employment, partnership, joint venture or agency. The Associate is not an employee, partner, agent or authorised signatory of NexaCrest and has no authority to bind NexaCrest in any way.'),
    ('c', '2.3', '**Non-exclusive.** NexaCrest may appoint other associates, agents or staff anywhere and may sell directly to any Buyer in any country. No territory, Buyer or product is reserved for the Associate except as stated in Clause 4.'),
    ('c', '2.4', '**No fee.** The Associate pays nothing to join or to remain an associate. NexaCrest will never ask for any fee or deposit.'),
    ('c', '2.5', '**Own costs.** The Associate bears all of its own costs and expenses (travel, communication, entertainment and similar). NexaCrest reimburses nothing unless it has agreed in writing before the cost is incurred.'),
]))

S.append(('3.  WHAT THE ASSOCIATE MAY AND MAY NOT DO', [
    ('c', '3.1', '**Allowed.** The Associate may introduce Buyers, discuss their requirements, pass on Price Answers, negotiate with a Buyer within the range from the quote price down to the lowest price of a valid Price Answer, and pass the Buyer\'s requests to NexaCrest.'),
    ('c', '3.2', '**Needs NexaCrest\'s prior written approval.** Without NexaCrest\'s prior written approval the Associate shall not promise, offer or agree:'),
    ('s', 'a', 'a price below the lowest price of a valid Price Answer;'),
    ('s', 'b', 'any delivery date, quantity, specification, quality, payment term, Incoterm, discount, credit, sample or any other term that is not in a Price Answer or in a document issued by NexaCrest; or'),
    ('s', 'c', 'any representation or warranty about NexaCrest or the Products.'),
    ('c', '3.3', '**Only NexaCrest issues documents.** Every quotation, Proforma Invoice, order confirmation, contract, invoice and other commercial or shipping document is issued by NexaCrest alone. A document not issued by NexaCrest has no effect and does not bind NexaCrest.'),
    ('c', '3.4', '**Strictly forbidden — money and documents.** The Associate shall never, under any circumstances:'),
    ('s', 'a', 'receive, collect or hold any payment, advance, deposit, sample charge or other money from any Buyer or Client, in cash, by bank transfer, cheque, card, digital wallet, cryptocurrency or in any other form, whether for itself, for NexaCrest or for anyone else;'),
    ('s', 'b', 'give a Buyer or Client any bank account, payment link or payment instruction, other than to pay NexaCrest\'s own account stated on NexaCrest\'s own documents;'),
    ('s', 'c', 'ask or encourage a Buyer or Client to pay any person or account other than NexaCrest\'s own account; or'),
    ('s', 'd', 'issue, change or forward as genuine any invoice or document in the name of NexaCrest or any of its divisions.'),
    ('p', 'All Orders, payments and documents pass directly between the Client and NexaCrest.'),
    ('c', '3.5', 'A breach of Clause 3.4 is a material breach. NexaCrest may terminate this Agreement immediately without notice; all unpaid Commission relating to that Client is forfeited; the Associate shall indemnify NexaCrest under Clause 14; and NexaCrest may inform the Buyer and the authorities.'),
    ('c', '3.6', '**Name and brand.** The Associate shall not use the name, logo, trademarks, website, e-mail domain or social-media identity of NexaCrest or its divisions, except materials NexaCrest provides for the purpose, and shall describe itself only as an independent associate, never as an employee or office of NexaCrest.'),
    ('c', '3.7', '**No sub-agents.** The Associate shall not appoint sub-agents or share any Commission with any other person, and shall not assign or subcontract any part of this Agreement.'),
    ('c', '3.8', '**Honest conduct.** The Associate shall not make any false or misleading statement about NexaCrest, the Products, their origin, quality or availability.'),
]))

S.append(('4.  CLIENTS', [
    ('c', '4.1', 'A Buyer becomes the Associate\'s Client when (a) the Buyer registers with NexaCrest through the Associate\'s personal registration link or using the Associate\'s personal code, and (b) NexaCrest checks and accepts the registration. NexaCrest may refuse any registration at its sole discretion, including a Buyer already known to or dealing with NexaCrest.'),
    ('c', '4.2', 'If more than one associate introduces the same Buyer, the Buyer belongs to the associate whose registration link or personal code the Buyer used, as shown in NexaCrest\'s records.'),
    ('c', '4.3', '**Registration period.** If an accepted Buyer places no Order within the registration period in Schedule A, the registration ends automatically and the Buyer is no longer the Associate\'s Client. The Buyer may then register through another associate or deal with NexaCrest directly, and the Associate has no claim to any Commission on that Buyer. The Portal reminds the Associate before the registration ends.'),
    ('c', '4.4', 'Once a Client has placed an Order that is not cancelled or lost, the Client remains the Associate\'s Client for as long as this Agreement is in force. This ends when the Agreement ends (Clause 13.4).'),
    ('c', '4.5', 'All Clients are and remain clients of NexaCrest for all purposes, during and after this Agreement. The Associate acquires no right in any Client relationship. NexaCrest may at any time decline any Buyer, Client or Order, change or stop any Product or price, without any liability for Commission.'),
]))

S.append(('5.  PRICES', [
    ('c', '5.1', 'The Associate obtains prices only through the Portal. Each Price Answer is valid until the date shown on it (see Schedule A). After that date the Associate shall ask again.'),
    ('c', '5.2', 'The Associate may negotiate between the quote price and the lowest price of a valid Price Answer. The Commission rate is the same anywhere in that range.'),
    ('c', '5.3', 'A price below the lowest price needs the prior written approval of NexaCrest\'s Managing Director. No Commission is payable on that line, even when approved.'),
    ('c', '5.4', 'The price to the Client is decided by NexaCrest alone and may include an amount set by NexaCrest for commission. The Associate has no say in, and no claim regarding, the Client\'s price or NexaCrest\'s costs or margins.'),
    ('c', '5.5', 'A Price Answer is guidance for the Associate only and is not an offer. Only NexaCrest\'s own Quotation, Proforma Invoice or order confirmation binds NexaCrest, on the terms and for the validity stated on it.'),
    ('c', '5.6', '**Fair use.** Price requests are for genuine enquiries only. NexaCrest may limit the number of price requests per day and may suspend price requests for an Associate who asks for many prices without Orders.'),
]))

S.append(('6.  COMMISSION', [
    ('c', '6.1', '**Orders that earn.** Every Order of a Client placed while this Agreement is in force earns Commission if its FOB Value of Goods is at least the Minimum Order Value in Schedule A. A Client\'s first Order always earns Commission, whatever its value.'),
    ('c', '6.2', '**Basis.** Commission is a percentage of the FOB Value of Goods of the Order. Commission rates exclude GST.'),
    ('c', '6.3', '**Rate.** The rate follows the commission ladder in Schedule A, according to the number of Counting Orders, unless NexaCrest has set a different ladder or a fixed rate for the Associate in writing.'),
    ('c', '6.4', 'The rate of each Order is fixed on the date its Proforma Invoice is issued. A later change does not affect it.'),
    ('c', '6.5', 'A cancelled or lost Order no longer counts as a step on the ladder. Earlier Orders keep their rate.'),
    ('c', '6.6', '**No Commission** is payable on:'),
    ('s', 'a', 'any Order that is cancelled or lost, or for which NexaCrest does not receive Full Payment, at whatever stage of production or shipment;'),
    ('s', 'b', 'any advance or part payment — Commission is earned only on Full Payment;'),
    ('s', 'c', 'any line priced below the lowest price (Clause 5.3);'),
    ('s', 'd', 'any Order of a Buyer who is not the Associate\'s Client when the Order\'s Proforma Invoice is issued, or any Order whose Proforma Invoice is issued after this Agreement ends;'),
    ('s', 'e', 'freight, insurance, services and any other charges; or'),
    ('s', 'f', 'any Order or Client affected by a breach of Clauses 3, 9, 10 or 11.'),
    ('c', '6.7', 'If an Order is reduced, amended or partly cancelled, Commission is calculated on the final FOB Value of Goods actually invoiced and paid in full.'),
    ('c', '6.8', '**Changes by Rate Notice.** NexaCrest may change any item of the Commission Schedule by Rate Notice given in writing through the Portal or by e-mail. A change applies only to Orders whose Proforma Invoice is issued after the date of the Rate Notice and never to earlier Orders. The Associate\'s acknowledgement is recorded in the Portal; continuing to introduce Buyers after a Rate Notice is acceptance of it. An Associate who does not accept a change may end this Agreement under Clause 13.2.'),
    ('c', '6.9', 'The Portal shows each Order, its status and its Commission.'),
]))

S.append(('7.  WHEN AND HOW COMMISSION IS PAID', [
    ('c', '7.1', '**Earned on Full Payment.** Commission is earned only when NexaCrest has received Full Payment for the Order. It is then fixed in the Order currency and in Indian Rupees at the exchange rate NexaCrest records for that payment, and does not change afterwards.'),
    ('c', '7.2', '**Payout.** Earned Commission is paid in NexaCrest\'s payout on the payout day and according to the cut-off day stated in Schedule A.'),
    ('c', '7.3', '**Bank transfer only.** Commission is always paid in Indian Rupees, by bank transfer only, into the Associate\'s own bank account held in the Associate\'s own name and verified by NexaCrest. No cash, cheque or third-party payment is made. A change of bank account is made only through the Portal, with confirmation, and is used only after NexaCrest has verified it.'),
    ('c', '7.4', '**Associate outside India.** NexaCrest sends the fixed Rupee amount. The Associate\'s bank converts it at its own rate and its own charges are for the Associate. NexaCrest pays its own and intermediary bank charges unless it notifies otherwise. A payout below the minimum payout abroad in Schedule A is carried forward to the next payout.'),
    ('c', '7.5', '**Conditions.** No Commission is paid until the Associate\'s KYC is complete and accepted by NexaCrest — identity proof, address proof, bank proof, the signed Agreement and a live video KYC call, and also PAN, and a GST certificate if registered, for an Associate resident in India — and, where NexaCrest requires it, the Associate has uploaded its invoice for the Commission in the Portal. The Associate warrants that all KYC documents and information are true and shall inform NexaCrest of any change.'),
    ('c', '7.6', '**Holds.** Payment of Commission on an Order is held while a Client claim on that Order, raised on or before the due date, remains unresolved, and during any investigation under Clause 12.4.'),
    ('c', '7.7', '**Payment advice.** NexaCrest issues a payment advice for each payment. Any objection must be raised in writing within thirty (30) days of the payment advice; after that the payment advice is final and accepted, except in case of fraud.'),
    ('c', '7.8', '**No take-back after payment.** Once paid, Commission is not recovered because of a later Client claim, refund, discount or return, except (a) in case of fraud or breach of this Agreement by the Associate, or (b) where an amount was paid by clear error (such as a wrong figure or a duplicate payment), which NexaCrest corrects by a revised payment advice and by deduction from the next payout(s), or which the Associate refunds within thirty (30) days of written demand.'),
    ('c', '7.9', '**Set-off.** NexaCrest may set off against any Commission any amount the Associate owes to NexaCrest under this Agreement.'),
]))

S.append(('8.  TAXES', [
    ('c', '8.1', 'Commission rates exclude GST. An Associate registered for GST in India shall issue a valid tax invoice for each Commission through the Portal; NexaCrest then pays the applicable GST in addition to the Commission.'),
    ('c', '8.2', 'NexaCrest deducts tax at source (TDS) and any other tax as required by law and provides the certificate for it.'),
    ('c', '8.3', 'An Associate resident outside India shall provide the documents NexaCrest reasonably requires to make payments abroad lawfully. NexaCrest complies with Indian law on such payments.'),
    ('c', '8.4', 'The Associate is solely responsible for its own income tax and all other taxes, registrations and filings in any country, and indemnifies NexaCrest against them.'),
]))

S.append(('9.  NON-CIRCUMVENTION AND NON-SOLICITATION', [
    ('c', '9.1', 'During this Agreement and for twenty-four (24) months after it ends for any reason, the Associate shall not, directly or indirectly (including through relatives, companies or any other person):'),
    ('s', 'a', 'sell, supply or arrange the supply of goods of the kind offered by NexaCrest to any Client, or to any Buyer the Associate introduced to or learned of through NexaCrest, other than through NexaCrest;'),
    ('s', 'b', 'buy from, sell for or deal with any supplier, quarry, factory, processor, transporter, freight forwarder or other business partner of NexaCrest that the Associate came to know through NexaCrest, in connection with any such Client or Buyer, without NexaCrest\'s prior written consent; or'),
    ('s', 'c', 'solicit or induce any employee, associate or business partner of NexaCrest to leave NexaCrest or to reduce its business with NexaCrest.'),
    ('c', '9.2', 'This Clause protects NexaCrest\'s Confidential Information, business relationships and goodwill. It does not prevent the Associate from lawful trade or employment that does not use NexaCrest\'s Confidential Information or the relationships described above.'),
    ('c', '9.3', 'On a breach of this Clause no Commission is payable on the affected business, NexaCrest may terminate this Agreement immediately, and Clause 14 applies.'),
]))

S.append(('10.  CONFLICT OF INTEREST, ANTI-BRIBERY AND COMPLIANCE', [
    ('c', '10.1', 'While this Agreement is in force the Associate shall not, without NexaCrest\'s prior written consent, act for any competitor of NexaCrest in the sale of goods of the same kind as the Products to Clients or Buyers introduced under this Agreement.'),
    ('c', '10.2', '**Declaration.** In Schedule B the Associate declares any employment, directorship, shareholding, agency or other paid relationship with any Buyer, Client, supplier or competitor of NexaCrest, and shall inform NexaCrest in writing of any new one at once. The Associate shall not accept any payment, gift or other benefit from any Buyer or supplier in connection with any Order.'),
    ('c', '10.3', '**Anti-bribery.** The Associate shall comply with the Prevention of Corruption Act, 1988 and all anti-bribery and anti-corruption laws that apply to it, and shall not offer, give, ask for or accept any bribe, secret commission or improper benefit. No part of any Commission may be shared with any employee, officer or agent of a Buyer.'),
    ('c', '10.4', '**Sanctions and export rules.** The Associate shall not introduce any Buyer that is subject to sanctions or located where trade is prohibited under applicable law, and shall inform NexaCrest at once of any such concern.'),
    ('c', '10.5', 'A breach of this Clause is a material breach.'),
]))

S.append(('11.  CONFIDENTIALITY AND PORTAL SECURITY', [
    ('c', '11.1', 'The Associate shall keep all Confidential Information strictly confidential and use it only for this Agreement, during this Agreement and at all times after it ends.'),
    ('c', '11.2', '**Prices.** The Associate may tell a Buyer the price for that Buyer\'s own enquiry, but shall not pass on, copy, screenshot, publish or forward any Portal page, price breakdown, Price Answer or list of prices.'),
    ('c', '11.3', '**Login.** The Associate shall keep its Portal password and login codes secret, never share them, and is responsible for everything done with its login. NexaCrest will never ask for the password or a login code.'),
    ('c', '11.4', 'This Clause does not apply to information that is public through no fault of the Associate, or that must be disclosed by law (and then only to the extent required, after telling NexaCrest where lawful).'),
    ('c', '11.5', 'When this Agreement ends, the Associate shall within fifteen (15) days return or delete all Confidential Information and confirm this in writing.'),
]))

S.append(('12.  PORTAL, RECORDS AND INVESTIGATIONS', [
    ('c', '12.1', 'The Portal is the official channel for registrations, Price Answers, Orders, statements, Rate Notices, payment advices and notices. Acknowledgements given in the Portal are recorded and binding.'),
    ('c', '12.2', '**NexaCrest\'s records decide.** NexaCrest\'s records (the Portal, its books of account and its bank records) are conclusive evidence of registrations, Orders, payments, Counting Orders and Commission, except for a manifest error.'),
    ('c', '12.3', 'NexaCrest decides which Portal features and information are available to the Associate and may change them.'),
    ('c', '12.4', '**Suspension during an investigation.** If NexaCrest reasonably suspects a breach of this Agreement, it may suspend the Associate\'s Portal access, price requests and payment of Commission for as long as reasonably needed to investigate. If no breach is found, held Commission is paid in the next payout, without interest.'),
]))

S.append(('13.  TERM AND TERMINATION', [
    ('c', '13.1', 'This Agreement starts on the Effective Date and continues until [[No expiry / DD Month YYYY]], unless ended earlier under this Clause.'),
    ('c', '13.2', 'Either Party may end this Agreement by giving thirty (30) days\' written notice, without giving a reason.'),
    ('c', '13.3', 'NexaCrest may end this Agreement immediately by written notice if the Associate: (a) breaches Clause 3, 9, 10 or 11; (b) gives false KYC documents or information; (c) commits fraud or dishonesty; (d) is convicted of an offence involving dishonesty; (e) becomes insolvent; or (f) acts in a way that harms NexaCrest\'s reputation.'),
    ('c', '13.4', '**Effect of ending.** On the end date the Associate\'s Portal access ends. Commission on Orders whose Proforma Invoice was issued before the end date and that later reach Full Payment remains payable under this Agreement, unless the Agreement was ended for a breach affecting those Orders. No Commission is payable on any Order whose Proforma Invoice is issued on or after the end date. Registrations without an Order end, and Clients cease to be the Associate\'s Clients.'),
    ('c', '13.5', 'Clauses 1, 7 (for Commission already earned), 8, 9, 10, 11, 12.2, 14, 15, 16, 17 and 18 survive the end of this Agreement.'),
]))

S.append(('14.  LIABILITY, INDEMNITY AND REMEDIES', [
    ('c', '14.1', 'The Associate shall indemnify and keep NexaCrest harmless against all losses, claims, damages, penalties, taxes and costs (including legal costs) arising from any breach of this Agreement, any unauthorised representation, any money received from a Buyer, any false KYC information, or any act or omission of the Associate.'),
    ('c', '14.2', 'NexaCrest\'s total liability under or in connection with this Agreement is limited to the Commission due and unpaid on the Order(s) in dispute. NexaCrest is not liable for any indirect or consequential loss, or for loss of profit, business or opportunity.'),
    ('c', '14.3', '**Agreed compensation.** For each breach of Clause 3.4 or Clause 9, the Associate shall pay NexaCrest, as reasonable compensation under Section 74 of the Indian Contract Act, 1872, the higher of [[₹5,00,000]] or [[10%]] of the FOB Value of Goods of the business involved in the breach, in addition to the forfeiture of Commission. For a breach of Clause 3.4 the Associate shall also hand over to NexaCrest at once every amount received from any Buyer or Client.'),
    ('c', '14.4', 'Damages may not be an adequate remedy for a breach of Clauses 3.4, 9 or 11, and NexaCrest may seek an injunction or other interim relief.'),
]))

S.append(('15.  PERSONAL DATA', [
    ('c', '15.1', 'The Associate consents to NexaCrest collecting, storing and using its personal data (identity, contact, KYC, bank and tax details) for this Agreement, for payments and for tax and legal compliance, in accordance with the Digital Personal Data Protection Act, 2023. Bank and KYC details are seen only by NexaCrest\'s authorised staff and are kept for as long as the law requires.'),
    ('c', '15.2', 'The Associate shall handle any personal data of Buyers lawfully and only for the purpose of this Agreement.'),
]))

S.append(('16.  NOTICES', [
    ('c', '16.1', 'Notices under this Agreement are given in writing by e-mail to the e-mail address for notices stated above, through the Portal, or by courier or registered post to the address stated above. An e-mail is received on the next Working Day after it is sent; a Portal notice when it is posted; a courier or registered letter three (3) Working Days after dispatch (seven (7) Working Days if sent outside India).'),
    ('c', '16.2', 'Each Party shall notify any change of its address or e-mail in writing.'),
]))

S.append(('17.  GOVERNING LAW AND DISPUTES', [
    ('c', '17.1', 'This Agreement is governed by the laws of India.'),
    ('c', '17.2', 'The Parties shall first try to settle any dispute by discussion within thirty (30) days of a written notice of dispute.'),
    ('c', '17.3', 'A dispute not settled is finally resolved by arbitration under the Arbitration and Conciliation Act, 1996, by a sole arbitrator appointed by agreement of the Parties or, failing agreement within thirty (30) days, in accordance with the said Act. The seat and venue of arbitration is Bengaluru, Karnataka, India, and the language is English. The award is final and binding.'),
    ('c', '17.4', 'Subject to Clause 17.3, the courts at Bengaluru have exclusive jurisdiction, including for interim relief.'),
]))

S.append(('18.  GENERAL', [
    ('c', '18.1', '**Entire agreement.** This Agreement, its Schedules and any Rate Notices are the entire agreement on its subject and replace all earlier discussions and agreements. Orders whose Proforma Invoice was issued before the Effective Date remain under the terms that applied to them.'),
    ('c', '18.2', '**Changes.** Any change must be in writing and signed by both Parties, except changes by Rate Notice under Clause 6.8.'),
    ('c', '18.3', '**Assignment.** The Associate may not assign or transfer this Agreement. NexaCrest may assign it to a group company or a successor.'),
    ('c', '18.4', '**Waiver and severability.** A delay in exercising a right is not a waiver. If any provision is invalid, the rest remains in force and the invalid provision applies to the maximum extent permitted.'),
    ('c', '18.5', '**Force majeure.** Neither Party is liable for delay caused by events beyond its reasonable control, except for payment obligations already due.'),
    ('c', '18.6', '**Signing.** This Agreement may be signed in counterparts, by wet ink signature, or electronically by a digital signature certificate or Aadhaar e-Sign under the Information Technology Act, 2000. NexaCrest signs by its authorised signatory\'s signature and company seal as printed on this Agreement. For the Associate, a scanned or pasted image of a signature alone is not a valid signature. Each page is initialled by both Parties.'),
    ('c', '18.7', '**Language.** This Agreement is made in English, which prevails over any translation.'),
]))

# ------------------------------------------------------------------ schedules
def sched_table(rows_data, cols=(2600, 3400, 3638)):
    head = row(''.join(cell(w, para(t, 18, 'FFFFFF', True, before=0, after=0), NAVY) for w, t in zip(cols, ('Item', 'Value', 'How it works'))))
    body = ''.join(row(cell(cols[0], para(a, 18, NAVY, True, before=0, after=0), LABELFILL)
                       + cell(cols[1], para(b, before=0, after=0)) + cell(cols[2], para(c, 17, before=0, after=0)), cant_split=True)
                   for a, b, c in rows_data)
    return tbl(list(cols), head + body)


schedule_a = [
    ('Commission ladder', '[[2%]] for Counting Orders 1–5\n', 'Percentage of the FOB Value of Goods of each Order; the rate is fixed when the Order\'s Proforma Invoice is issued.'),
]
ladder = '[[2%]] — Counting Orders 1 to 5;  [[5%]] — 6 to 10;  [[7%]] — 11 to 20;  [[10%]] — 21 onward'
schedule_a = [
    ('Commission ladder', ladder, 'Percentage of the FOB Value of Goods of each Order; the rate is fixed when the Order\'s Proforma Invoice is issued.'),
    ('Associate-specific rule', '[[None]]', 'An own ladder or a fixed rate applies only if NexaCrest states it here or in a Rate Notice.'),
    ('Minimum Order Value', '[[₹5,00,000]] FOB Value of Goods', 'An Order below this value earns no Commission and does not count, except the Client\'s first Order, which always earns.'),
    ('Counting Order', 'An Order at or above the Minimum Order Value', 'Counts as a step once the Client\'s advance is cleared (letter of credit Orders: once the LC is confirmed; no advance and no LC: on Full Payment). A cancelled or lost Order loses its step; earlier Orders keep their rate.'),
    ('Grouping of Orders', '[[15]] days', 'Orders of the same Client whose Proforma Invoices are issued within this many days of the first Order of the group count as one step.'),
    ('Registration period', '[[6]] months', 'From NexaCrest\'s acceptance of the Buyer. With no Order by then, the registration ends (Clause 4.3).'),
    ('Price Answer validity', '[[30]] days', 'From the date of the Price Answer; the same period as NexaCrest\'s Quotation.'),
    ('Payout day', '[[Last day of each month]]', 'If it is not a Working Day, the payout is made on the Working Day before.'),
    ('Cut-off day', '[[20th]] of the month', 'Full Payment received by the cut-off day: paid in that month\'s payout. Received later: paid in the next month\'s payout.'),
    ('Minimum payout abroad', '[[USD 100]] (equivalent)', 'Smaller amounts due to an Associate outside India are carried forward to the next payout.'),
]

example = box(para('**Example (for illustration only):** a Client\'s Order has an FOB Value of Goods of USD 10,000 and is the Associate\'s 3rd Counting Order (rate 2%). Commission = USD 200. When the Client pays in full, the USD 200 is fixed in Rupees at the exchange rate NexaCrest records for that payment (for example ₹80 per USD = ₹16,000) and that Rupee amount does not change. It is paid in the monthly payout according to the cut-off day.', 17, italic=False))

schedule_b = box(
    para('The Associate declares that:')
    + sub('a', 'the Associate is resident in [[India / Country]] and is [[registered / not registered]] for GST;')
    + sub('b', 'the Associate has the following employment, directorship, shareholding, agency or other paid relationships with any Buyer, Client, supplier or competitor of NexaCrest: [[None / details]];')
    + sub('c', 'the Associate is not subject to any sanctions and is not employed by any Buyer it will introduce;')
    + sub('d', 'all KYC documents and information given to NexaCrest are true and complete; and')
    + sub('e', 'the Associate has read and understood this Agreement and Schedule A, and has had the opportunity to take independent advice.'))

# ------------------------------------------------------------------ signatures
def sig_lines(title, lines):
    x = para(title, 20, NAVY, True, before=0, after=120)
    for l in lines:
        x += para(l, 18, before=0, after=160)
    return x

sig_left = (para('For', 20, NAVY, True, before=0, after=20)
            + para('NexaCrest International Private Limited', 20, NAVY, True, before=0, after=120)
            + '<w:p><w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr><w:r><w:t>__SEAL__</w:t></w:r></w:p>')
sig_right = (para('Authorised Signatory', 20, GREY, True, before=0, after=60)
             + '<w:p><w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr><w:r><w:t>__SIGN__</w:t></w:r></w:p>' + '<w:p><w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr><w:r><w:t>__DSEAL__</w:t></w:r></w:p>'
             + f'<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="6" w:space="4" w:color="000000"/></w:pBdr><w:spacing w:before="0" w:after="20"/></w:pPr>{runs("Managing Director", 24, NAVY, True)}</w:p>'
             + para('Founder & Managing Director', 20, '000000', True, before=0, after=20)
             + para('NexaCrest International Private Limited', 17, GREY, before=0, after=120)
             )
sig_noborder = lambda w, inner: (f'<w:tc><w:tcPr><w:tcW w:w="{w}" w:type="dxa"/><w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
                                 f'<w:tcMar><w:top w:w="60" w:type="dxa"/><w:left w:w="0" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="160" w:type="dxa"/></w:tcMar></w:tcPr>{inner}</w:tc>')
signatures = (tbl([half, FULL - half], row(sig_noborder(half, sig_left) + sig_noborder(FULL - half, sig_right)), borders=False)
              + spacer() + bar('FOR THE ASSOCIATE') + spacer()
              + kv_table([('Full Legal Name{*}', ' '), ('Associate Code', ' '),
                          ('Signature{*}', ' '), ('Date{*}', ' '), ('Place{*}', ' ')]))
witnesses = tbl([half, FULL - half], row(
    cell(half, sig_lines('Witness 1', ['Signature: ________________________', 'Name: ____________________________', 'Address: __________________________', '__________________________________']), 'FFFFFF', (120, 160))
    + cell(FULL - half, sig_lines('Witness 2', ['Signature: ________________________', 'Name: ____________________________', 'Address: __________________________', '__________________________________']), 'FFFFFF', (120, 160))))

# ------------------------------------------------------------------ assemble
parts = [header, intro, info, spacer(), bar('PARTIES'), spacer(), parties_tbl, spacer(), parties_text, spacer(), bar('BACKGROUND'), spacer(), background, spacer()]
for title, items in S:
    parts.append(section(title, items))
parts += [bar('SCHEDULE A — COMMISSION SCHEDULE'), spacer(),
          box(para('The values below apply until changed by a Rate Notice (Clause 6.8). A change applies only to Orders whose Proforma Invoice is issued after the Rate Notice.', 17, GREY, italic=True)),
          spacer(), sched_table(schedule_a), spacer(), example, spacer(),
          bar('SCHEDULE B — ASSOCIATE DECLARATIONS'), spacer(), schedule_b, spacer(),
          '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
          bar('SIGNATURES'), spacer(),
          para('By signing below, both Parties confirm they have read, understood and agree to be bound by all terms of this Agreement. NexaCrest\'s authorised signature and company seal appear below; the Associate signs in ink or electronically under Clause 18.6. Both Parties initial every page.', 18, GREY, italic=True, before=60, after=60),
          spacer(), signatures, spacer(), witnesses]

d = docx.Document()
sec = d.sections[0]
sec.page_width, sec.page_height = Mm(210), Mm(297)
sec.top_margin = sec.bottom_margin = Mm(12.7)
sec.left_margin = sec.right_margin = Mm(15)
body = d.element.body
for el in list(body):
    if not el.tag.endswith('sectPr'):
        body.remove(el)
sect = body[-1]
for p in parts:
    wrapper = parse_xml(f'<w:body {W}>' + p.replace(f' {W}', '') + '</w:body>')
    for child in list(wrapper):
        sect.addprevious(child)

from docx.text.paragraph import Paragraph
from docx.shared import Mm as _Mm
for p_el in body.iter('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}p'):
    if ''.join(t.text or '' for t in p_el.iter('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t')) == '__LOGO__':
        para_obj = Paragraph(p_el, d._body)
        for r in list(para_obj.runs):
            r._r.getparent().remove(r._r)
        para_obj.add_run().add_picture(IMG['logo'], width=_Mm(19), height=_Mm(19))
        break
# NexaCrest's signature and seals (owner 26 Nov 2026: keep our seal and signature, as on the NDA)
IMAGES = {'__SEAL__': ('company_seal', 45, 45), '__SIGN__': ('signature', 53, 6.35), '__DSEAL__': ('director_seal', 31.75, 31.75)}
for p_el in list(body.iter('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}p')):
    txt = ''.join(t.text or '' for t in p_el.iter('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t'))
    if txt in IMAGES:
        f, w, h = IMAGES[txt]
        para_obj = Paragraph(p_el, d._body)
        for r in list(para_obj.runs):
            r._r.getparent().remove(r._r)
        para_obj.add_run().add_picture(IMG[f], width=_Mm(w), height=_Mm(h))

# footer: agreement no., page x of y, initials
footer = sec.footer
fp = footer.paragraphs[0]
fp._p.getparent().remove(fp._p)
fld = lambda instr: (f'<w:r>{rpr(15, GREY)}<w:fldChar w:fldCharType="begin"/></w:r><w:r>{rpr(15, GREY)}<w:instrText xml:space="preserve"> {instr} </w:instrText></w:r>'
                     f'<w:r>{rpr(15, GREY)}<w:fldChar w:fldCharType="separate"/></w:r><w:r>{rpr(15, GREY)}<w:t>1</w:t></w:r><w:r>{rpr(15, GREY)}<w:fldChar w:fldCharType="end"/></w:r>')
foot_xml = (f'<w:p {W}><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="{BORDER}"/></w:pBdr><w:spacing w:before="0" w:after="0"/><w:tabs><w:tab w:val="right" w:pos="{FULL}"/></w:tabs></w:pPr>'
            + runs('Associate Agreement · [[SC/ASA/YYYY/NNN]] · Rev.00 · Page ', 15, GREY) + fld('PAGE') + runs(' of ', 15, GREY) + fld('NUMPAGES')
            + f'<w:r>{rpr(15, GREY)}<w:tab/></w:r>' + runs('Initials — NexaCrest: ________   Associate: ________', 15, GREY) + '</w:p>')
footer._element.append(parse_xml(foot_xml))

d.core_properties.title = 'Commission Structure and Associate Agreement (template)'
d.core_properties.author = 'NexaCrest International Private Limited'
d.core_properties.comments = 'Template. Yellow fields are placeholders filled in for each Associate. Review by the company lawyer before first use.'
d.save(sys.argv[1])
print('saved', sys.argv[1])
