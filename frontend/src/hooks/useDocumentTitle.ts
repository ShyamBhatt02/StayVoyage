import { useEffect } from 'react';

/** Client-side page title + meta description (server injects the initial ones for SEO). */
export function usePageMeta(title: string, description?: string) {
  useEffect(() => {
    const prev = document.title;
    document.title = title.includes('StayVoyage') ? title : `${title} | StayVoyage`;
    let tag = document.querySelector<HTMLMetaElement>('meta[name="description"]');
    const prevDesc = tag?.content;
    if (description) {
      if (!tag) {
        tag = document.createElement('meta');
        tag.name = 'description';
        document.head.appendChild(tag);
      }
      tag.content = description;
    }
    return () => {
      document.title = prev;
      if (tag && prevDesc !== undefined) tag.content = prevDesc;
    };
  }, [title, description]);
}
