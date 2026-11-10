-- Opens a file in Microsoft Excel for Mac and prints what Excel shows: every sheet's used range
-- values and number formats, the freeze pane, the autofilter and the column widths. A repair
-- prompt is modal and blocks the open command, so a timeout means Excel refused the file as is.
--
-- Usage: osascript excel-inspect.applescript /absolute/path/to/file
-- The file must lie inside ~/Library/Containers/com.microsoft.Excel/Data/ so that the sandboxed
-- Excel can open it without a file-access prompt.

on run argv
	set p to item 1 of argv
	set out to ""
	with timeout of 45 seconds
		tell application "Microsoft Excel"
			set openBefore to count of workbooks
			open (POSIX file p)
			if (count of workbooks) is openBefore then error "Excel did not open the file"
			set wb to active workbook
			set out to out & "workbook=" & (name of wb) & linefeed
			repeat with k from 1 to (count of worksheets of wb)
				set ws to worksheet k of wb
				activate object ws
				set out to out & "sheet=" & (name of ws) & linefeed
				set r to used range of ws
				set out to out & "used=" & (get address of r) & linefeed
				try
					set out to out & "autofilter=" & (autofilter mode of ws) & linefeed
				end try
				set w to active window
				set out to out & "freeze=" & (freeze panes of w) & " splitrow=" & (split row of w) & linefeed
				set widths to ""
				repeat with c from 1 to (count of columns of r)
					set widths to widths & (column width of column c of ws) & ","
				end repeat
				set out to out & "widths=" & widths & linefeed
				set rowCount to count of rows of r
				repeat with i from 1 to rowCount
					set line_ to ""
					repeat with c from 1 to (count of columns of r)
						set cl to cell i of column c of r
						set f to formula of cl
						set line_ to line_ & (string value of cl) & " {" & (number format of cl) & "}"
						if f starts with "=" then set line_ to line_ & " [" & f & "]"
						set line_ to line_ & " | "
					end repeat
					set out to out & "row" & i & "=" & line_ & linefeed
				end repeat
			end repeat
			close wb saving no
		end tell
	end timeout
	return out
end run
