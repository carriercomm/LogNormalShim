<?php

die("This isn't really a script; it just contains notes in comment form.");

/* 
 * performance.lognormal.daily.(load|moe|n|perc95|perc98)               # backfill_daily, scrape_daily
 * performance.lognormal.intraday.(load|moe|n)                          # backfill_intraday, scrape_intraday
 *
 * performance.lognormal.daily.bouncerate
 *
 * performance.lognormal.daily.                                         # scrape_drilldowns, backfill_drilldowns
 *      pages.(search|listing|homepage|...).     # backfill/scrape _ daily _ drilldowns
 *          browsers.(Chrome_20|...).(load|moe|n|perc95|perc98)
 *          countries.(US|AU|CN|...).(load|moe|n|perc95|perc98)
 *          bandwidth.(...).(load|moe|n|perc95|perc98)
 */
