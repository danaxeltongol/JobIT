<?php
// ============================================================================
// JOBIT SORTING (our own code, no sort(), usort() or ORDER BY is used for ranking)
//
//   mergeSortByKey()   -> the sort JobIT uses to rank listings and applicants
//   topKByKey()        -> picks only the best K rows with a MIN-HEAP (Home page "top 3")
//   bubbleSortByKey()  -> a slow sort, only used by benchmark.php to compare
//
// Both sort a list of rows (arrays) from HIGHEST to LOWEST using one number in
// each row, for example $row['match_score'] or $row['budget_max'].
// Both are STABLE: if two rows have the same number, they keep the order they
// already had (the database gives the starting order, for example newest listing first).
// Both count how many times two numbers were compared, we call this $comparisons.
// ============================================================================


// ----------------------------------------------------------------------------
// MERGE SORT   (divide and conquer, about n x log2(n) comparisons)
//
//   1. SPLIT the list into a left half and a right half
//   2. keep splitting until every piece has only 1 item (1 item is already sorted)
//   3. MERGE two sorted pieces into one bigger sorted piece, again and again
//
//   Example: [60, 90, 75, 80]
//            split -> [60, 90]   [75, 80]
//            split -> [60] [90]  [75] [80]
//            merge -> [90, 60]   [80, 75]
//            merge -> [90, 80, 75, 60]
//
//   $rows         the list to sort (the original list is NOT changed)
//   $key          which number to sort by, for example 'match_score'
//   $comparisons  gets filled with the number of comparisons (optional)
//   returns       a new list, highest first
// ----------------------------------------------------------------------------
function mergeSortByKey($rows, $key, &$comparisons = 0) {
    $comparisons = 0;                                   // start counting from zero
    return mergeSortStep(array_values($rows), $key, $comparisons);
}

// One step of the merge sort: split the list in two, sort each half, then merge them.
function mergeSortStep($rows, $key, &$comparisons) {
    $count = count($rows);

    // Base case: a list with 0 or 1 item is already sorted, nothing to do
    if ($count <= 1) {
        return $rows;
    }

    // SPLIT in the middle
    $middle = intdiv($count, 2);
    $leftHalf  = array_slice($rows, 0, $middle);
    $rightHalf = array_slice($rows, $middle);

    // Sort each half (the function calls itself, this is called recursion)
    $leftSorted  = mergeSortStep($leftHalf, $key, $comparisons);
    $rightSorted = mergeSortStep($rightHalf, $key, $comparisons);

    // MERGE the two sorted halves into one sorted list
    return mergeTwoSortedLists($leftSorted, $rightSorted, $key, $comparisons);
}

// Merges two lists that are ALREADY sorted (highest first) into one sorted list.
// We only ever look at the first unused item of each list and take the bigger one.
function mergeTwoSortedLists($left, $right, $key, &$comparisons) {
    $merged = [];
    $i = 0;                         // position in the left list
    $j = 0;                         // position in the right list
    $leftCount = count($left);
    $rightCount = count($right);

    // While both lists still have items, compare their first unused items
    while ($i < $leftCount && $j < $rightCount) {
        $comparisons++;             // one comparison of two numbers
        // ">=" (not ">") means: on a tie take the LEFT one first, so equal rows keep their order
        if ($left[$i][$key] >= $right[$j][$key]) {
            $merged[] = $left[$i];
            $i++;
        } else {
            $merged[] = $right[$j];
            $j++;
        }
    }

    // One list is finished, the other one still has items: they are already in order, copy them
    while ($i < $leftCount) {
        $merged[] = $left[$i];
        $i++;
    }
    while ($j < $rightCount) {
        $merged[] = $right[$j];
        $j++;
    }

    return $merged;
}


// ----------------------------------------------------------------------------
// BUBBLE SORT   (simple but slow, up to n x (n-1) / 2 comparisons)
// Used only by benchmark.php to show why merge sort is better.
//
//   Walk through the list again and again. Every time two neighbours are in the
//   wrong order (smaller one first) swap them. After each walk the smallest
//   number "bubbles" to the end. Stop when a whole walk needs no swap.
// ----------------------------------------------------------------------------
function bubbleSortByKey($rows, $key, &$comparisons = 0) {
    $comparisons = 0;
    $rows = array_values($rows);
    $count = count($rows);

    for ($pass = 0; $pass < $count - 1; $pass++) {
        $swapped = false;

        // The last $pass items are already in their final place, so we skip them
        for ($i = 0; $i < $count - 1 - $pass; $i++) {
            $comparisons++;
            // "<" (not "<=") means equal rows are never swapped, so the sort stays stable
            if ($rows[$i][$key] < $rows[$i + 1][$key]) {
                $temp = $rows[$i];
                $rows[$i] = $rows[$i + 1];
                $rows[$i + 1] = $temp;
                $swapped = true;
            }
        }

        // No swap in a whole walk = the list is sorted, stop early
        if (!$swapped) {
            break;
        }
    }

    return $rows;
}


// ----------------------------------------------------------------------------
// TOP-K WITH A MIN-HEAP   (about n x log2(k) comparisons)
//
// The Home page only shows the best 3 gigs. Sorting ALL n gigs to keep 3 is
// wasted work, so we keep a small "heap" of size k instead.
//
//   A min-heap is a list stored like a tree:  the children of position i are
//   at 2i+1 and 2i+2, and every parent is SMALLER than its children.
//   So the WORST of the k rows we are keeping is always at position 0 (the top).
//
//   1. Walk through the rows once.
//   2. While the heap has fewer than k rows, add the row (and move it up to its place).
//   3. After that, a new row only gets in if it is BETTER than the worst one at
//      the top: it replaces the top, then moves down to its place.
//   4. At the end the heap holds the best k rows; take them out worst first and
//      flip the list, so the result is best first.
//
//   Example, k = 3, scores [60, 90, 75, 80, 50]
//     add 60, 90, 75         heap top (worst kept) = 60
//     80 > 60  -> replace     heap top = 75
//     50 < 75  -> ignored
//     result: [90, 80, 75]
//
// Ties: on the same score the row that came FIRST in the list counts as better,
// exactly like our stable merge sort, so both always give the same top k.
//
//   $rows         the list (not changed)
//   $key          which number to rank by, for example 'match_score'
//   $k            how many rows to keep, for example 3
//   $comparisons  gets filled with the number of comparisons (optional)
//   returns       the best $k rows, best first
// ----------------------------------------------------------------------------
function topKByKey($rows, $key, $k, &$comparisons = 0) {
    $comparisons = 0;
    $rows = array_values($rows);
    if ($k <= 0) {
        return [];
    }

    // Each heap item is [row, position in the original list]; the position breaks ties
    $heap = [];
    foreach ($rows as $position => $row) {
        $item = [$row, $position];
        if (count($heap) < $k) {
            $heap[] = $item;                                         // add at the end...
            heapMoveUp($heap, count($heap) - 1, $key, $comparisons);   // ...then up to its place
        } else {
            $comparisons++;
            if (heapIsWorse($heap[0], $item, $key)) {                // new row beats the worst one we keep
                $heap[0] = $item;                                    // replace the top...
                heapMoveDown($heap, 0, $key, $comparisons);          // ...then down to its place
            }
        }
    }

    // Take the rows out worst first, then flip so the best is first
    $result = [];
    while (!empty($heap)) {
        $result[] = $heap[0][0];
        $last = array_pop($heap);
        if (!empty($heap)) {
            $heap[0] = $last;
            heapMoveDown($heap, 0, $key, $comparisons);
        }
    }
    return array_reverse($result);
}

// Is heap item $a WORSE than item $b? (lower score, or same score but it came later in the list)
function heapIsWorse($a, $b, $key) {
    if ($a[0][$key] != $b[0][$key]) {
        return $a[0][$key] < $b[0][$key];
    }
    return $a[1] > $b[1];
}

// Move the item at $i up while it is worse than its parent (keeps the worst at the top)
function heapMoveUp(&$heap, $i, $key, &$comparisons) {
    while ($i > 0) {
        $parent = intdiv($i - 1, 2);
        $comparisons++;
        if (!heapIsWorse($heap[$i], $heap[$parent], $key)) {
            break;
        }
        $temp = $heap[$i]; $heap[$i] = $heap[$parent]; $heap[$parent] = $temp;   // swap with the parent
        $i = $parent;
    }
}

// Move the item at $i down while one of its children is worse than it
function heapMoveDown(&$heap, $i, $key, &$comparisons) {
    $size = count($heap);
    while (true) {
        $left = 2 * $i + 1;
        $right = 2 * $i + 2;
        $worst = $i;
        if ($left < $size) {
            $comparisons++;
            if (heapIsWorse($heap[$left], $heap[$worst], $key)) { $worst = $left; }
        }
        if ($right < $size) {
            $comparisons++;
            if (heapIsWorse($heap[$right], $heap[$worst], $key)) { $worst = $right; }
        }
        if ($worst === $i) {
            break;                                                   // both children are better: done
        }
        $temp = $heap[$i]; $heap[$i] = $heap[$worst]; $heap[$worst] = $temp;      // swap with the worse child
        $i = $worst;
    }
}
