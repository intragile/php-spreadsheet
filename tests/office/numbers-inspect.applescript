-- Opens a file in Apple Numbers and prints every sheet's first table: size, header rows (Numbers
-- turns a frozen row into a header row) and every cell's formatted value.
--
-- Usage: osascript numbers-inspect.applescript /absolute/path/to/file
-- The file must lie inside ~/Library/Containers/com.apple.iWork.Numbers/Data/ so that the
-- sandboxed Numbers can open it without a file-access prompt.

on run argv
	set p to item 1 of argv
	set out to ""
	with timeout of 60 seconds
		tell application "Numbers"
			open (POSIX file p)
			delay 2
			set d to front document
			set out to out & "document=" & (name of d) & linefeed
			repeat with k from 1 to (count of sheets of d)
				set s to sheet k of d
				set t to table 1 of s
				set out to out & "sheet=" & (name of s) & " rows=" & (row count of t) & " cols=" & (column count of t) & " headerRows=" & (header row count of t) & linefeed
				repeat with i from 1 to (row count of t)
					set line_ to ""
					repeat with c from 1 to (column count of t)
						set cl to cell c of row i of t
						set line_ to line_ & (formatted value of cl)
						try
							set f to formula of cl
							if f is not missing value then set line_ to line_ & " [" & f & "]"
						end try
						set line_ to line_ & " | "
					end repeat
					set out to out & "row" & i & "=" & line_ & linefeed
				end repeat
			end repeat
			close d saving no
		end tell
	end timeout
	return out
end run
