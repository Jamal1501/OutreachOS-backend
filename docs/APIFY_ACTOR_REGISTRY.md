# Apify Actor Registry

This file documents which Apify actor is used for which pipeline step, what input it expects, and which Google Sheet tab it should write to.

## Pipeline rule

- Discovery actors write only to raw post sheets
- Enrichment actors write only to enriched profile sheets
- Never import discovery output into enriched profile sheets
- Never import raw actor output directly into `Creators_CRM`

---

## 1) Instagram hashtag discovery

Actor ID: `reGe1ST3OBgYZSsZJ`  
Purpose: Discover Instagram posts/reels by hashtag  
Pipeline stage: Discovery  
Input type: hashtags  
Target sheet: `Instagram_Posts_Raw`  
Status: Confirmed discovery actor  

### Input JSON

```json
{
  "hashtags": [
    "ldr",
    "longdistance",
    "longdistancerelationship",
    "fernbeziehung",
    "couples",
    "couplesgift",
    "couplesgifts",
    "weddinggift",
    "weddinginspo",
    "couplesinspo",
    "giftideas",
    "personalgift",
    "customgift",
    "bestgift",
    "puzzlelovers"
  ],
  "keywordSearch": false,
  "resultsLimit": 20,
  "resultsType": "reels"
}
Notes

Use only for discovery. Do not use for profile enrichment.

2) Instagram profile enrichment

Actor ID: shu8hvrXbJbY3Eb9W
Purpose: Enrich Instagram creator profiles from profile URLs
Pipeline stage: Enrichment
Input type: directUrls
Target sheet: Instagram_Profile_Enriched
Status: Confirmed enrichment actor

Input JSON
{
  "addParentData": false,
  "directUrls": [
    "https://www.instagram.com/artsy.bando/",
    "https://www.instagram.com/catalina.delesco/",
    "https://www.instagram.com/cnet/",
    "https://www.instagram.com/digital_art.nand/",
    "https://www.instagram.com/ekammeyer/",
    "https://www.instagram.com/thechefquelli_experience/"
  ],
  "onlyPostsNewerThan": "100 days",
  "resultsLimit": 3,
  "resultsType": "details",
  "search": "ldr",
  "searchLimit": 10,
  "searchType": "hashtag"
}
Notes

Use after extracting Instagram profile URLs from discovery results.

3) TikTok hashtag discovery

Actor ID: GdWCkxBtKWOsKjdch
Purpose: Discover TikTok posts/videos by hashtag
Pipeline stage: Discovery
Input type: hashtags
Target sheet: TikTok_Posts_Raw
Status: Confirmed discovery actor

Input JSON
{
  "hashtags": [
    "ldr",
    "longdistance",
    "longdistancerelationship",
    "fernbeziehung",
    "couples",
    "couplesgift",
    "couplesgifts",
    "weddinggift",
    "weddinginspo",
    "couplesinspo",
    "giftideas",
    "personalgift",
    "customgift",
    "bestgift",
    "puzzlelovers"
  ],
  "keywordSearch": false,
  "resultsLimit": 20,
  "resultsType": "reels"
}
Notes

Confirmed working for TikTok raw discovery import. Do not import this actor into Instagram sheets.

## 4) TikTok profile-input actor (post-output)

Actor ID: `0FXVyOXXEmdGcV88a`
Purpose: Scrapes TikTok posts from specific profile usernames
Pipeline stage: Mixed
Input type: profiles
Target sheet: `TikTok_Posts_Raw`
Status: Tested - output is post-centric, not clean profile enrichment

### Input JSON
```json
{
  "excludePinnedPosts": false,
  "profiles": [
    "apifyoffice"
  ],
  "shouldDownloadAvatars": false,
  "shouldDownloadCovers": false,
  "shouldDownloadSlideshowImages": false,
  "shouldDownloadSubtitles": false,
  "shouldDownloadVideos": false
}
Notes

Looks like a profile enricher because it uses profiles, not hashtags. Final confirmation depends on output shape.

Google Sheet mapping
Discovery outputs

Instagram hashtag discovery -> Instagram_Posts_Raw

TikTok hashtag discovery -> TikTok_Posts_Raw

Enrichment outputs

Instagram profile enrichment -> Instagram_Profile_Enriched

TikTok profile enrichment -> TikTok_Profile_Enriched

Downstream

Enriched profile sheets are merged into Creators_CRM

Outreach tasks are created in Task_Queue

Outreach actions are logged in Outreach_Log
