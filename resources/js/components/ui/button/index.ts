import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-1 whitespace-nowrap rounded-lg text-sm font-medium cursor-pointer transition-control disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground hover:bg-primary-hover active:translate-y-px disabled:bg-border-strong disabled:text-subtle-foreground disabled:opacity-100",
        destructive:
          "bg-critical text-foreground hover:bg-critical-hover active:translate-y-px disabled:bg-border-strong disabled:text-subtle-foreground disabled:opacity-100",
        outline:
          "border border-border-strong bg-transparent text-foreground hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-border-strong",
        ghost:
          "text-foreground hover:bg-accent hover:text-accent-foreground disabled:text-subtle-foreground disabled:opacity-100",
        link: "text-primary-text underline-offset-4 hover:text-primary-text-hover hover:underline",
      },
      size: {
        "default": "h-8 px-3",
        "sm": "h-7 px-2 rounded-md",
        "lg": "h-10 gap-2 px-4",
        "icon": "size-8",
        "icon-sm": "size-7 rounded-md",
        "icon-xs": "size-6 rounded-md",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
