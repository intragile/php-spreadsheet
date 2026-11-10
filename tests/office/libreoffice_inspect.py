# LibreOffice Calc macro that opens spreadsheet files in a visible window and prints what Calc
# shows: per sheet the frozen rows, the first column widths and the first data row, and every
# autofilter range. Headless conversions skip view settings, so frozen panes need a real window.
#
# Run it through libreoffice-inspect.sh, which installs the macro into a throwaway profile and
# passes the files in LO_INSPECT_FILES (separated by newlines) and the report path in LO_INSPECT_OUT.

import os

import uno


def main(*args):
    ctx = XSCRIPTCONTEXT.getComponentContext()  # noqa: F821 (provided by the macro runtime)
    desktop = ctx.ServiceManager.createInstanceWithContext("com.sun.star.frame.Desktop", ctx)
    lines = []

    for path in filter(None, os.environ.get("LO_INSPECT_FILES", "").split("\n")):
        try:
            doc = desktop.loadComponentFromURL(uno.systemPathToFileUrl(path), "_blank", 0, ())
        except Exception as error:  # a file Calc refuses is part of the report, not a crash
            lines.append(f"file={os.path.basename(path)} error={error!r}")
            continue

        controller = doc.getCurrentController()
        lines.append(f"file={os.path.basename(path)}")

        for index in range(doc.Sheets.Count):
            sheet = doc.Sheets.getByIndex(index)
            controller.setActiveSheet(sheet)
            widths = [sheet.Columns.getByIndex(column).Width for column in range(8)]
            row = [sheet.getCellByPosition(column, 1).String for column in range(8)]
            lines.append(f"  sheet={sheet.Name} frozen={controller.hasFrozenPanes()} splitRow={controller.getSplitRow()}")
            lines.append(f"  widths(1/100 mm)={widths}")
            lines.append(f"  row2={row}")

        unnamed = doc.getPropertyValue("UnnamedDatabaseRanges")

        for index in range(doc.Sheets.Count):
            if unnamed.hasByTable(index):
                area = unnamed.getByTable(index).DataArea
                lines.append(
                    f"  autofilter sheet={index} enabled={unnamed.getByTable(index).AutoFilter} "
                    f"rows={area.StartRow + 1}-{area.EndRow + 1} columns={area.StartColumn + 1}-{area.EndColumn + 1}"
                )

        doc.close(True)

    with open(os.environ["LO_INSPECT_OUT"], "w", encoding="utf-8") as report:
        report.write("\n".join(lines) + "\n")
