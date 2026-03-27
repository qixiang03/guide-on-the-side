# accessibility-dashboard

`accessibility-dashboard` is a custom **Guide-on-the-Side accessibility plugin** developed to manage accessibility settings.

This plugin is part of the **Guide-On-the-Side** project.

---

## Purpose

Many users can face difficulties navigating and operating online web applications.
`accessibility-dashboard` addresses this by:

- Creating custom visual and interactive accessibility functionality
- Centralizing accessibility settings into one location
- Integrating smoothly with Pressbooks/WordPress interface

---

## Key Features

- Colorblind friendly color scheme alongside a default UPEI Library color scheme
- Custom focus indicators 
- Sitewide font selection with choices from the UPEI Library Default (consisting of Lusitana, Roboto, and Roboto Condensed), Arial, Verdana, and Tahoma
- Custom keyboard shortcuts for Guide-on-the-Side tutorials allowing for easy forward and backward navigation or focus changes

---

## How It Works
Defines custom user metadata values which contain user accessibility preferences for color schemes, focus indication, font families, and tutorial keyboard shortcuts.

### Installation

1. Copy the plugin accessibility-dashboard into:

```
web/app/plugins/

```

2. Activate it in:

```
Admin → Plugins

```
3. Modify User Profile:

```
Profile → Administration Color Scheme
Profile → Accessibility Settings → Enable Custom Focus Indicators
Profile → Accessibility Settings → Sitewide Font Family
Profile → Accessibility Settings → Custom Keyboard Shortcuts

```


### Usage

- Adjust focus indicators across the site with custom outline color and width
- Enable colorblind friendly administration color schemes
- Select preferred font family
- Define custom shortcuts for use within Guide-on-the-Side tutorials