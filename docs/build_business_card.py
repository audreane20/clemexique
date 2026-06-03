from pathlib import Path

from reportlab.graphics.barcode.qr import QrCodeWidget
from reportlab.graphics.shapes import Drawing
from reportlab.lib.colors import Color, HexColor, white
from reportlab.lib.units import inch
from reportlab.pdfgen import canvas


ROOT = Path(__file__).resolve().parents[1]
OUTPUT_DIR = ROOT / "docs"
LOGO_PATH = ROOT / "public" / "Images" / "Circle_logo-cropped.png"
PDF_PATH = OUTPUT_DIR / "business-card-clemexique.pdf"
CARD_WIDTH = 3.5 * inch
CARD_HEIGHT = 2.0 * inch
SITE_URL = "https://clemexique.ca"

TEAL = HexColor("#2F6F66")
SAND = HexColor("#F1E6D3")
GOLD = HexColor("#D8B36A")
DEEP = HexColor("#143A36")
MIST = HexColor("#E9F3F1")
SHADOW = Color(0, 0, 0, alpha=0.12)


def draw_qr(pdf: canvas.Canvas, x: float, y: float, size: float) -> None:
    qr = QrCodeWidget(SITE_URL)
    bounds = qr.getBounds()
    qr_width = bounds[2] - bounds[0]
    qr_height = bounds[3] - bounds[1]
    drawing = Drawing(
        size,
        size,
        transform=[size / qr_width, 0, 0, size / qr_height, 0, 0],
    )
    drawing.add(qr)
    drawing.drawOn(pdf, x, y)


def build_pdf() -> None:
    pdf = canvas.Canvas(str(PDF_PATH), pagesize=(CARD_WIDTH, CARD_HEIGHT))

    pdf.setTitle("CLeMexique Business Card")
    pdf.setAuthor("OpenAI Codex")
    pdf.setSubject("Business card with QR code for clemexique.ca")

    pdf.setFillColor(TEAL)
    pdf.roundRect(0, 0, CARD_WIDTH, CARD_HEIGHT, 16, fill=1, stroke=0)

    pdf.setFillColor(SAND)
    pdf.roundRect(8, 8, CARD_WIDTH - 16, CARD_HEIGHT - 16, 14, fill=1, stroke=0)

    qr_x = CARD_WIDTH - 96
    qr_y = 23
    qr_size = 62

    pdf.setFillColor(SHADOW)
    pdf.roundRect(qr_x + 7, qr_y - 6, qr_size, qr_size, 12, fill=1, stroke=0)

    pdf.setFillColor(white)
    pdf.roundRect(qr_x, qr_y, qr_size, qr_size, 10, fill=1, stroke=0)
    draw_qr(pdf, qr_x + 5, qr_y + 5, qr_size - 10)

    if LOGO_PATH.exists():
        pdf.drawImage(
            str(LOGO_PATH),
            18,
            CARD_HEIGHT - 60,
            width=34,
            height=34,
            mask="auto",
            preserveAspectRatio=True,
        )

    pdf.setFillColor(DEEP)
    pdf.setFont("Helvetica-Bold", 15)
    pdf.drawString(56, CARD_HEIGHT - 25, "CLeMexique")

    pdf.setFillColor(GOLD)
    pdf.setFont("Helvetica-Bold", 7.5)
    pdf.drawString(56, CARD_HEIGHT - 37, "RIVIERA MAYA GUIDE")

    pdf.setFillColor(DEEP)
    pdf.setFont("Helvetica-Bold", 10)
    pdf.drawString(18, 62, "Scan to visit the site")

    pdf.setFillColor(TEAL)
    pdf.setFont("Helvetica", 7.6)
    pdf.drawString(18, 48, "Properties, excursions, restaurants")
    pdf.drawString(18, 38, "and local favorites in Riviera Maya")

    pdf.setFillColor(DEEP)
    pdf.setFont("Helvetica-Bold", 9.5)
    pdf.drawString(18, 20, SITE_URL.replace("https://", ""))

    pdf.setStrokeColor(MIST)
    pdf.setLineWidth(1)
    pdf.line(18, 30, CARD_WIDTH - 104, 30)

    pdf.setFillColor(TEAL)
    pdf.setFont("Helvetica-Bold", 6.5)
    pdf.drawCentredString(qr_x + (qr_size / 2), qr_y - 10, "SCAN ME")

    pdf.showPage()
    pdf.save()
if __name__ == "__main__":
    build_pdf()
